<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 4 2020
 */

namespace MikoPBX\Core\System;

use Exception;
use MikoPBX\Common\Models\{LanInterfaces};
use MikoPBX\Core\Utilities\SubnetCalculator;
use Phalcon\Di;

/**
 * Class Network
 */
class Network
{
    /**
     * @var mixed|\Phalcon\Di\DiInterface|null
     */
    private $di;

    /**
     * Network constructor.
     */
    public function __construct()
    {
        $this->di = Di::getDefault();
    }

    public static function startSipDump(): void
    {
        $config = new MikoPBXConfig();
        $use    = $config->getGeneralSettings('USE_PCAP_SIP_DUMP');
        if ($use !== '1') {
            return;
        }

        Util::killByName('pcapsipdump');
        $log_dir = System::getLogDir() . '/pcapsipdump';
        Util::mwMkdir($log_dir);

        $network = new Network();
        $arr_eth = $network->getInterfacesNames();
        foreach ($arr_eth as $eth) {
            $pid_file = "/var/run/pcapsipdump_{$eth}.pid";
            Util::mwExecBg(
                'pcapsipdump -T 120 -P ' . $pid_file . ' -i ' . $eth . ' -m \'^(INVITE|REGISTER)$\' -L ' . $log_dir . '/dump.db'
            );
        }
    }

    /**
     * Имена всех подключенных сетевых интерфейсов.
     */
    public function getInterfacesNames()
    {
        // Универсальная команда для получения всех PCI сетевых интерфейсов.
        Util::mwExec("ls -l /sys/class/net | grep pci | awk '{ print $9 }'", $names);

        return $names;
    }

    /**
     * Up loopback.
     **/
    public function loConfigure()
    {
        if (Util::isSystemctl()) {
            return;
        }
        Util::mwExec("/bin/busybox ifconfig lo 127.0.0.1");
    }

    /**
     * Generates resolv.conf
     **/
    public function resolvConfGenerate(): void
    {
        $resolv_conf   = '';
        $data_hostname = self::getHostName();
        if (trim($data_hostname['domain']) !== '') {
            $resolv_conf .= "domain {$data_hostname['domain']}\n";
        }

        $resolv_conf .= "nameserver 127.0.0.1\n";

        $named_dns = [];
        $dns       = $this->getHostDNS();
        foreach ($dns as $ns) {
            if (trim($ns) === '') {
                continue;
            }
            $named_dns[] = $ns;
            $resolv_conf .= "nameserver {$ns}\n";
        }
        if (count($dns) === 0) {
            $resolv_conf .= "nameserver 4.4.4.4\n";
            $named_dns[] .= "8.8.8.8";
        }

        if (Util::isSystemctl()) {
            $s_resolv_conf = "[Resolve]\n"
                . "DNS=127.0.0.1\n";
            if (trim($data_hostname['domain']) !== '') {
                $s_resolv_conf .= "Domains={$data_hostname['domain']}\n";
            }
            file_put_contents('/etc/systemd/resolved.conf', $s_resolv_conf);
            Util::mwExec('systemctl restart systemd-resolved');
        } else {
            file_put_contents('/etc//resolv.conf', $resolv_conf);
        }

        $this->generatePdnsdConfig($named_dns);
    }

    /**
     * Возвращает имя сервера в виде ассоциативного массива.
     *
     * @return array
     */
    public static function getHostName(): array
    {
        $data = [
            'hostname' => 'mikopbx',
            'domain'   => '',
        ];
        /** @var \MikoPBX\Common\Models\LanInterfaces $res */
        $res = LanInterfaces::findFirst("internet = '1'");
        if (null !== $res) {
            $data['hostname'] = $res->hostname;
            $data['domain']   = $res->domain;
        }
        $data['hostname'] = (empty($data['hostname'])) ? 'mikopbx' : $data['hostname'];

        return $data;
    }

    /**
     * Возвращает массив DNS серверов из настроек.
     *
     * @return array
     */
    public function getHostDNS(): array
    {
        $dns = [];
        /** @var \MikoPBX\Common\Models\LanInterfaces $res */
        $res = LanInterfaces::findFirst("internet = '1'");
        if (null !== $res) {
            if ( ! empty($res->primarydns) && '127.0.0.1' != $res->primarydns) {
                $dns[] = $res->primarydns;
            }
            if ( ! empty($res->secondarydns) && '127.0.0.1' != $res->secondarydns) {
                $dns[] = $res->secondarydns;
            }
        }

        return $dns;
    }

    /**
     * Настройка кэширующего DNS сервера.
     *
     * @param $named_dns
     */
    private function generatePdnsdConfig($named_dns): void
    {
        $tempPath  = $this->di->getShared('config')->get('core.tempPath');
        $cache_dir = $tempPath . '/pdnsd/cache';
        if ( ! file_exists($cache_dir) && ! mkdir($cache_dir, 0777, true) && ! is_dir($cache_dir)) {
            $cache_dir = '/var/spool';
        }

        $conf = 'global {' . "\n" .
            '	perm_cache=10240;' . "\n" .
            '	cache_dir="' . $cache_dir . '";' . "\n" .
            '	pid_file = /var/run/pdnsd.pid;' . "\n" .
            '	run_as="nobody";' . "\n" .
            '	server_ip = 127.0.0.1;' . "\n" .
            '	status_ctl = on;' . "\n" .
            '	query_method=udp_tcp;' . "\n" .
            '	min_ttl=15m;' . "\n" .
            '	max_ttl=1w;' . "\n" .
            '	timeout=10;' . "\n" .
            '	neg_domain_pol=on;' . "\n" .
            '	run_as=root;' . "\n" .
            '	daemon=on;' . "\n" .
            '}' . "\n" .
            'server {' . "\n" .
            '	label = "main";' . "\n" .
            '	ip = ' . implode(', ', $named_dns) . ';' . "\n" .
            '	interface=lo;' . "\n" .
            '	uptest=if;' . "\n" .
            '	interval=10m;' . "\n" .
            '	purge_cache=off;' . "\n" .
            '}';
        file_put_contents('/etc/pdnsd.conf', $conf);

        $pid = Util::getPidOfProcess('/usr/sbin/pdnsd');
        if ( ! empty($pid)) {
            // Завершаем процесс.
            Util::mwExec("/bin/busybox kill '$pid'");
        }
        if (Util::isSystemctl()) {
            Util::mwExec("systemctl restart pdnsd");
        } else {
            Util::mwExec("/usr/sbin/pdnsd -c /etc/pdnsd.conf -4");
        }
    }

    /**
     * Configures LAN interface
     *
     * @return int
     */
    public function lanConfigure(): int
    {
        if (Util::isSystemctl()) {
            $this->lanConfigureSystemCtl();

            return 0;
        }
        $networks     = $this->getGeneralNetSettings();
        $arr_commands = [];

        $eth_mtu = [];
        foreach ($networks as $if_data) {
            if ($if_data['disabled'] === '1') {
                continue;
            }

            $if_name = $if_data['interface'];
            $if_name = escapeshellarg(trim($if_name));
            if (empty($if_name)) {
                continue;
            }

            $data_hostname = self::getHostName();
            $hostname      = $data_hostname['hostname'];

            if ($if_data['vlanid'] > 0) {
                // Переопределяем имя интерфейса.
                $arr_commands[] = '/sbin/vconfig set_name_type VLAN_PLUS_VID_NO_PAD';
                // Добавляем новый интерфейс.
                $arr_commands[] = "/sbin/vconfig add {$if_data['interface_orign']} {$if_data['vlanid']}";
            }
            // Отключаем интерфейс.
            $arr_commands[] = "/bin/busybox ifconfig $if_name down";
            $arr_commands[] = "/bin/busybox ifconfig $if_name 0.0.0.0";

            $gw_param = '';
            if ($if_data['dhcp'] === '1') {
                /*
                 * -t - количество попыток.
                 * -T - таймаут попытки.
                 * -v - включить отладку.
                 * -S - логи в syslog.
                 * -q - Exit after obtaining lease
                 * -n - Exit if lease is not obtained
                 */
                $pid_file = "/var/run/udhcpc_{$if_name}";
                $pid_pcc  = Util::getPidOfProcess($pid_file);
                if ( ! empty($pid_pcc)) {
                    // Завершаем старый процесс.
                    system("kill `cat {$pid_file}` {$pid_pcc}");
                }
                // Получаем IP и дожидаемся завершения процесса.
                $workerPath     = '/etc/rc/udhcpc.configure';
                $options        = '-t 6 -T 5 -q -n';
                $arr_commands[] = "/sbin/udhcpc {$options} -i {$if_name} -x hostname:{$hostname} -s {$workerPath}";
                // Старутем новый процесс udhcpc в  фоне.
                $options        = '-t 6 -T 5 -S -b -n';
                $arr_commands[] = "nohup /sbin/udhcpc {$options} -p {$pid_file} -i {$if_name} -x hostname:{$hostname} -s {$workerPath} 2>&1 &";
                /*
                    udhcpc  - утилита произведет настройку интерфейса
                               - произведет конфигурацию /etc/resolv.conf
                    Дальнейшая настройка маршрутов будет произволиться в udhcpcConfigureRenewBound();
                    и в udhcpcConfigureDeconfig(). Эти методы будут вызваны скриптом WorkerUdhcpcConfigure.php.
                    // man udhcp
                    // http://pwet.fr/man/linux/administration_systeme/udhcpc/

                */
            } else {
                $ipaddr  = trim($if_data['ipaddr']);
                $subnet  = trim($if_data['subnet']);
                $gateway = trim($if_data['gateway']);
                if (empty($ipaddr)) {
                    continue;
                }
                // Это короткое представление маск /24 /32.
                try {
                    $calc_subnet = new SubnetCalculator($ipaddr, $subnet);
                    $subnet      = $calc_subnet->getSubnetMask();
                } catch (Exception $e) {
                    echo "Caught exception: $ipaddr $subnet", $e->getMessage(), "\n";
                    continue;
                }

                $arr_commands[] = "/bin/busybox ifconfig $if_name $ipaddr netmask $subnet";

                if ("" != trim($gateway)) {
                    $gw_param = "gw $gateway";
                }

                $arr_commands[] = "/bin/busybox route del default $if_name";

                /** @var LanInterfaces $if_data */
                $if_data = LanInterfaces::findFirst("id = '{$if_data['id']}'");
                $is_inet = ($if_data !== null) ? $if_data->internet : 0;
                // Добавляем маршруты по умолчанию.
                if ($is_inet == 1) {
                    // ТОЛЬКО, если этот интерфейс для интернет, создаем дефолтный маршрут.
                    $arr_commands[] = "/bin/busybox route add default $gw_param dev $if_name";
                }
                // Поднимаем интерфейс.
                $arr_commands[] = "/bin/busybox ifconfig $if_name up";

                $eth_mtu[] = $if_name;
            }
        }
        $out = null;
        Util::mwExecCommands($arr_commands, $out, 'net');
        $this->hostsGenerate();

        foreach ($eth_mtu as $eth) {
            Util::mwExecBg("/etc/rc/networking.set.mtu '{$eth}'");
        }

        $firewall = new Firewall();
        $firewall->applyConfig();

        // Дополнительные "ручные" маршруты.
        Util::fileWriteContent('/etc/static-routes', '');
        $arr_commands = [];
        $out          = [];
        Util::mwExec(
            "/bin/cat /etc/static-routes | /bin/grep '^rout' | /bin/busybox awk -F ';' '{print $1}'",
            $arr_commands
        );
        Util::mwExecCommands($arr_commands, $out, 'rout');

        return 0;
    }

    /**
     * For OS systemctl (Debian).
     * Configures LAN interface
     */
    public function lanConfigureSystemCtl(): void
    {
        $networks = $this->getGeneralNetSettings();

        Util::mwExec('systemctl stop networking');
        Util::mwExec('modprobe 8021q');
        foreach ($networks as $if_data) {
            $if_name = trim($if_data['interface']);
            if ('' == $if_name) {
                continue;
            }
            $conf_file = "/etc/network/interfaces.d/{$if_name}";
            if ($if_data['disabled'] == 1) {
                Util::mwExec('ifdown eth0');
                if (file_exists($if_name)) {
                    unlink($conf_file);
                }
                continue;
            }
            $subnet  = trim($if_data['subnet']);
            $ipaddr  = trim($if_data['ipaddr']);
            $gateway = trim($if_data['gateway']);

            $result = [''];
            if (file_exists('/etc/static-routes')) {
                $command = "/bin/cat /etc/static-routes " .
                    "| /bin/grep '^rout' " .
                    "| /bin/busybox awk -F ';' '{print $1}' " .
                    "| grep '{$if_name}\$' " .
                    "| awk -F 'dev {$if_name}' '{ print $1 }'";
                Util::mwExec($command, $result);
            }
            $routs_add = ltrim(implode("\npost-up ", $result));
            $routs_rem = ltrim(implode("\npre-down ", $result));


            if ($if_data['vlanid'] > 0) {
                // Пока только статика.
                $lan_config = "auto {$if_data['interface_orign']}.{$if_data['vlanid']}\n" .
                    "iface {$if_data['interface_orign']}.{$if_data['vlanid']} inet static \n" .
                    "address {$ipaddr}\n" .
                    "netmask {$subnet}\n" .
                    "gateway {$gateway}\n" .
                    "dns-nameservers 127.0.0.1\n" .
                    "vlan_raw_device {$if_data['interface_orign']}\n" .
                    "{$routs_add}\n" .
                    "{$routs_rem}\n";
            } elseif ($if_data['dhcp'] == 1) {
                $lan_config = "auto {$if_name}\n" .
                    "iface {$if_name} inet dhcp\n" .
                    "{$routs_add}\n" .
                    "{$routs_rem}\n";
            } else {
                if (empty($ipaddr)) {
                    continue;
                }
                try {
                    $calc_subnet = new SubnetCalculator($ipaddr, $subnet);
                    $subnet      = $calc_subnet->getSubnetMask();
                } catch (Exception $e) {
                    echo "Caught exception: $ipaddr $subnet", $e->getMessage(), "\n";
                    continue;
                }
                $lan_config = "auto {$if_name}\n" .
                    "iface {$if_name} inet static\n" .
                    "address {$ipaddr}\n" .
                    "netmask {$subnet}\n" .
                    "gateway {$gateway}\n" .
                    "dns-nameservers 127.0.0.1\n" .
                    "{$routs_add}\n" .
                    "{$routs_rem}\n";
            }
            file_put_contents("/etc/network/interfaces.d/{$if_name}", $lan_config);
        }
        Util::mwExec('systemctl start networking');
        $this->hostsGenerate();

        $firewall = new Firewall();
        $firewall->applyConfig();
    }

    /**
     * Получение настроек интерфейсов LAN.
     *
     * @return array
     */
    public function getGeneralNetSettings(): array
    {
        // Массив сетевых интерфейсов, которые видит ОС.
        $src_array_eth = $this->getInterfacesNames();
        // Создаем копию массива сетевых интерфейсов.
        $array_eth = $src_array_eth;
        $res       = LanInterfaces::find(['order' => 'interface,vlanid']);
        $networks  = $res->toArray();
        if (count($networks) > 0) {
            // Дополнительная обработка данных.
            foreach ($networks as &$if_data) {
                $if_data['interface_orign'] = $if_data['interface'];
                $if_data['interface']       = ($if_data['vlanid'] > 0) ? "vlan{$if_data['vlanid']}" : $if_data['interface'];
                $if_data['dhcp']            = ($if_data['vlanid'] > 0) ? 0 : $if_data['dhcp'];

                if (Verify::isIpAddress($if_data['subnet'])) {
                    $if_data['subnet'] = $this->netMaskToCidr($if_data['subnet']);
                }

                $key = array_search($if_data['interface_orign'], $src_array_eth);
                if ($key !== false) {
                    // Интерфейс найден.
                    // Удаляем элемент массива, если это не VLAN.
                    if ($if_data['vlanid'] == 0) {
                        unset($array_eth[$key]);
                        $this->enableLanInterface($if_data['interface_orign']);
                    }
                } else {
                    // Интерфейс не существует.
                    $this->disableLanInterface($if_data['interface_orign']);
                    // Отключаем интерфейс.
                    $if_data['disabled'] = 1;
                }
            }
        } elseif (count($array_eth) > 0) {
            $networks = [];
            // Настраиваем основной интерфейс.
            $networks[] = $this->addLanInterface($array_eth[0], true);
            unset($array_eth[0]);
        }
        // $array_eth - в массиве останутся только те элементы,
        // по которым нет настроек в базе дынных.
        // Следует добавить настройки "по умолчанию".
        foreach ($array_eth as $eth) {
            // Добавляем. Все интерфейсы, отключаем.
            $networks[] = $this->addLanInterface($eth, false);
        }
        $res = LanInterfaces::findFirst("internet = '1' AND disabled='0'");
        if (null === $res) {
            /** @var \MikoPBX\Common\Models\LanInterfaces $eth_settings */
            $eth_settings = LanInterfaces::findFirst("disabled='0'");
            if ($eth_settings !== null) {
                $eth_settings->internet = 1;
                $eth_settings->save();
            }
        }

        return $networks;
    }

    /**
     * Преобразует сетевую маску в CIDR представление.
     *
     * @param $net_mask
     *
     * @return int
     */
    public function netMaskToCidr($net_mask): int
    {
        $bits     = 0;
        $net_mask = explode(".", $net_mask);

        foreach ($net_mask as $oct_ect) {
            $bits += strlen(str_replace("0", "", decbin($oct_ect)));
        }

        return $bits;
    }

    /**
     * Включаем интерфейс по его имени.
     *
     * @param $name
     */
    public function enableLanInterface($name): void
    {
        $parameters = [
            'conditions' => 'interface = :ifName: and disabled = :disabled:',
            'bind'       => [
                'ifName'   => $name,
                'disabled' => 1,
            ],
        ];

        $if_data = LanInterfaces::findFirst($parameters);
        if ($if_data !== null) {
            $if_data->disabled = 0;
            $if_data->update();
        }
    }

    /**
     * Удаляем интерфейс по его имени.
     *
     * @param $name
     */
    public function disableLanInterface($name): void
    {
        $if_data = LanInterfaces::findFirst("interface = '{$name}'");
        if ($if_data !== null) {
            $if_data->internet = 0;
            $if_data->disabled = 1;
            $if_data->update();
        }
    }

    /**
     * Добавляем в базу данных сведения о новом интерфейсе.
     *
     * @param      $name
     * @param bool $general
     *
     * @return mixed
     */
    private function addLanInterface($name, $general = false)
    {
        $disabled = 0; // ($general==true)?0:1;
        $dhcp     = 1; // ($general==true)?1:0;
        $internet = ($general == true) ? 1 : 0;

        $data = new LanInterfaces();
        $data->writeAttribute('name', $name);
        $data->writeAttribute('interface', $name);
        $data->writeAttribute('dhcp', $dhcp);
        $data->writeAttribute('internet', $internet);
        $data->writeAttribute('disabled', $disabled);
        $data->writeAttribute('vlanid', 0);
        $data->writeAttribute('hostname', 'mikopbx');
        $data->writeAttribute('domain', '');
        $data->writeAttribute('topology', 'private');
        $data->writeAttribute('primarydns', '');

        $data->save();

        return $data->toArray();
    }

    /**
     * Настройка hosts
     */
    public function hostsGenerate(): void
    {
        $s = new System();
        $s->hostnameConfigure();
    }

    /**
     * Configures LAN interface FROM udhcpc (renew_bound)
     */
    public function udhcpcConfigureRenewBound(): void
    {
        if (Util::isSystemctl()) {
            $this->udhcpcConfigureRenewBoundSystemCtl();

            return;
        }
        // Инициализация массива переменных.
        $env_vars = [
            'broadcast' => '',
            'interface' => '',
            'ip'        => '',
            'router'    => '',
            'timesvr'   => '',
            'namesvr'   => '',
            'dns'       => '',
            'hostname'  => '',
            'subnet'    => '',
            'serverid'  => '',
            'ipttl'     => '',
            'lease'     => '',
            'domain'    => '',
        ];

        $debugMode = $this->di->getShared('config')->path('core.debugMode');

        // Получаем значения переменных окружения.
        foreach ($env_vars as $key => $value) {
            $env_vars[$key] = trim(getenv($key));
        }
        $BROADCAST = ($env_vars['broadcast'] == '') ? "" : "broadcast {$env_vars['broadcast']}";
        $NET_MASK  = ($env_vars['subnet'] == '') ? "" : "netmask {$env_vars['subnet']}";

        // Настраиваем интерфейс.
        Util::mwExec("/bin/busybox ifconfig {$env_vars['interface']} {$env_vars['ip']} $BROADCAST $NET_MASK");

        // Удаляем старые маршруты по умолчанию.
        while (true) {
            $out = [];
            Util::mwExec("route del default gw 0.0.0.0 dev {$env_vars['interface']}", $out);
            if (trim(implode('', $out)) != '') {
                // Произошла ошибка, значит все маршруты очищены.
                break;
            }
            if ($debugMode) {
                break;
            } // Иначе бесконечный цикл.
        }
        // Добавляем маршруты по умолчанию.
        /** @var \MikoPBX\Common\Models\LanInterfaces $if_data */
        $if_data = LanInterfaces::findFirst("interface = '{$env_vars['interface']}'");
        $is_inet = ($if_data !== null) ? $if_data->internet : 0;
        if ('' != $env_vars['router'] && $is_inet == 1) {
            // ТОЛЬКО, если этот интерфейс для интернет, создаем дефолтный маршрут.
            $routers = explode(' ', $env_vars['router']);
            foreach ($routers as $router) {
                Util::mwExec("route add default gw {$router} dev {$env_vars['interface']}");
            }
        }
        // Добавляем пользовательские маршруты.
        Util::mwExec(
            "/bin/cat /etc/static-routes | /bin/grep '^rout' | /bin/busybox awk -F ';' '{print $1}' | grep '{$env_vars['interface']}' | sh"
        );

        $named_dns = [];
        if ('' !== $env_vars['dns']) {
            $named_dns = explode(' ', $env_vars['dns']);
        }
        if ($is_inet == 1) {
            // ТОЛЬКО, если этот интерфейс для интернет, правим resolv.conf.
            // Прописываем основные DNS.
            $this->generatePdnsdConfig($named_dns);
        }

        // Сохрании информацию в базу данных.
        $data = [
            'subnet'  => $env_vars['subnet'],
            'ipaddr'  => $env_vars['ip'],
            'gateway' => $env_vars['router'],
        ];
        if (Verify::isIpAddress($env_vars['ip'])) {
            $data['subnet'] = $this->netMaskToCidr($env_vars['subnet']);
        } else {
            $data['subnet'] = '';
        }
        $this->updateIfSettings($data, $env_vars['interface']);

        $data = [
            'primarydns'   => $named_dns[0] ?? '',
            'secondarydns' => $named_dns[1] ?? '',
        ];
        $this->updateDnsSettings($data, $env_vars['interface']);

        Util::mwExecBg("/etc/rc/networking.set.mtu '{$env_vars['interface']}'");
    }

    /**
     * For OS systemctl (Debian).
     * Configures LAN interface FROM dhcpc (renew_bound).
     */
    public function udhcpcConfigureRenewBoundSystemCtl(): void
    {
        // Инициализация массива переменных.
        $prefix   = "new_";
        $env_vars = [
            'broadcast' => 'broadcast_address',
            'interface' => 'interface',
            'ip'        => 'ip_address',
            'router'    => 'routers',
            'timesvr'   => '',
            'namesvr'   => 'netbios_name_servers',
            'dns'       => 'domain_name_servers',
            'hostname'  => 'host_name',
            'subnet'    => 'subnet_mask',
            'serverid'  => '',
            'ipttl'     => '',
            'lease'     => 'new_dhcp_lease_time',
            'domain'    => 'domain_name',
        ];

        // Получаем значения переменных окружения.
        foreach ($env_vars as $key => $value) {
            $var_name = "{$prefix}{$value}";
            if (empty($var_name)) {
                continue;
            }
            $env_vars[$key] = trim(getenv("{$prefix}{$value}"));
        }

        // Добавляем маршруты по умолчанию.
        /** @var \MikoPBX\Common\Models\LanInterfaces $if_data */
        $if_data = LanInterfaces::findFirst("interface = '{$env_vars['interface']}'");
        $is_inet = ($if_data !== null) ? $if_data->internet : 0;

        $named_dns = [];
        if ('' !== $env_vars['dns']) {
            $named_dns = explode(' ', $env_vars['dns']);
        }
        if ($is_inet == 1) {
            // ТОЛЬКО, если этот интерфейс для интернет, правим resolv.conf.
            // Прописываем основные DNS.
            $this->generatePdnsdConfig($named_dns);
        }
        // Сохрании информацию в базу данных.
        $data = [
            'subnet'  => $env_vars['subnet'],
            'ipaddr'  => $env_vars['ip'],
            'gateway' => $env_vars['router'],
        ];
        if (Verify::isIpAddress($env_vars['ip'])) {
            $data['subnet'] = $this->netMaskToCidr($env_vars['subnet']);
        } else {
            $data['subnet'] = '';
        }
        $this->updateIfSettings($data, $env_vars['interface']);
        $data = [
            'primarydns'   => $named_dns[0] ?? '',
            'secondarydns' => $named_dns[1] ?? '',
        ];
        $this->updateDnsSettings($data, $env_vars['interface']);
    }

    /**
     * Сохранение настроек сетевого интерфейса.
     *
     * @param $data
     * @param $name
     */
    public function updateIfSettings($data, $name): void
    {
        /** @var \MikoPBX\Common\Models\LanInterfaces $res */
        $res = LanInterfaces::findFirst("interface = '$name' AND vlanid=0");
        if ($res === null){
            return;
        }
        foreach ($data as $key => $value) {
            $res->writeAttribute("$key", "$value");
        }
        $res->save();
    }

    /**
     * Сохранение DNS настроек сетевого интерфейса.
     *
     * @param $data
     * @param $name
     */
    public function updateDnsSettings($data, $name): void
    {
        /** @var \MikoPBX\Common\Models\LanInterfaces $res */
        $res = LanInterfaces::findFirst("interface = '$name' AND vlanid=0");
        if ($res === null){
            return;
        }
        if (empty($res->primarydns) && ! empty($data['primarydns'])) {
            $res->writeAttribute('primarydns', $data['primarydns']);
        } elseif (empty($res->secondarydns) && $res->primarydns !== $data['primarydns']) {
            $res->writeAttribute('secondarydns', $data['primarydns']);
        }
        if (empty($res->secondarydns) && ! empty($data['secondarydns'])) {
            $res->writeAttribute('secondarydns', $data['secondarydns']);
        }
        $res->save();
    }

    /**
     * Возвращает имя интерфейса по его id.
     *
     * @param $id_net
     *
     * @return string
     */
    public function getInterfaceNameById($id_net): string
    {
        /** @var \MikoPBX\Common\Models\LanInterfaces $res */
        $res            = LanInterfaces::findFirst("id = '$id_net'");
        return ($res === null) ? '' : $res->interface;
    }

    /**
     * Возвращает список включеннх веб интерейсов
     *
     * @param $id_net
     *
     * @return string
     */
    public function getEnabledLanInterfaces(): array
    {
        /** @var \MikoPBX\Common\Models\LanInterfaces $res */
        $res = LanInterfaces::find('disabled=0');

        return $res->toArray();
    }

    /**
     * Configures LAN interface FROM udhcpc (deconfig)
     */
    public function udhcpcConfigureDeconfig(): void
    {
        // Настройка по умолчанию.
        $interface = trim(getenv('interface'));
        if ( ! Util::isSystemctl()) {
            // Для MIKO LFS Edition.
            Util::mwExec("/bin/busybox ifconfig $interface 192.168.2.1 netmask 255.255.255.0");
        }
        $data = [
            'subnet'  => '24',
            'ipaddr'  => '192.168.2.1',
            'gateway' => '',
        ];
        $this->updateIfSettings($data, $interface);
    }

    /**
     * Сохранение настроек сетевого интерфейса.
     *
     * @param $data
     */
    public function updateNetSettings($data): void
    {
        $res         = LanInterfaces::findFirst("internet = '1'");
        $update_inet = false;
        if ($res === null) {
            $res         = LanInterfaces::findFirst();
            $update_inet = true;
        }

        if ($res !== null) {
            foreach ($data as $key => $value) {
                $res->$key = $value;
            }
            if ($update_inet === true) {
                $res->internet = 1;
            }
            $res->save();
        }
    }

    /**
     * Возвращает массив с информацией по сетевым интерфейсам.
     *
     * @return array
     */
    public function getInterfaces(): array
    {
        // Получим все имена PCI интерфейсов (сеть).
        $i_names = $this->getInterfacesNames();
        $if_list = [];
        foreach ($i_names as $i) {
            $if_list[$i] = $this->getInterface($i);
        }

        return $if_list;
    }

    /**
     * Сбор информации по сетевому интерфейсу.
     *
     * @param $name
     *
     * @return array
     */
    public function getInterface($name): array
    {
        $interface = [];

        // Получаем ifconfig's для interface $name.
        Util::mwExec("/bin/busybox ifconfig $name 2>/dev/null", $output);
        $output = implode(" ", $output);

        // Парсим mac
        preg_match("/HWaddr (\S+)/", $output, $matches);
        $interface['mac'] = (count($matches) > 0) ? $matches[1] : '';

        // Парсим ip.
        preg_match("/inet addr:(\S+)/", $output, $matches);
        $interface['ipaddr'] = (count($matches) > 0) ? $matches[1] : '';

        // Парсим подсеть.
        preg_match("/Mask:(\S+)/", $output, $matches);
        $subnet              = (count($matches) > 0) ? $this->netMaskToCidr($matches[1]) : '';
        $interface['subnet'] = $subnet;

        // Поднят ли интерфейс?
        preg_match("/\s+(UP)\s+/", $output, $matches);
        $status = (count($matches) > 0) ? $matches[1] : '';
        if ($status == "UP") {
            $interface['up'] = true;
        } else {
            $interface['up'] = false;
        }

        Util::mwExec('/bin/busybox route -n | grep ' . $name . '| grep "^0.0.0.0" | cut -d " " -f 10', $matches);
        $gw = (count($matches) > 0) ? $matches[0] : '';
        if (Verify::isIpAddress($gw)) {
            $interface['gateway'] = $gw;
        }

        Util::mwExec('cat /etc/resolv.conf | grep "nameserver" | cut -d " " -f 2', $dnsout);

        $dnsSrv = [];
        foreach ($dnsout as $line) {
            if (Verify::isIpAddress($line)) {
                $dnsSrv[] = $line;
            }
        }
        $interface['dns'] = $dnsSrv;
        return $interface;
    }

}