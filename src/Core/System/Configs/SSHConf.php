<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright (C) 2017-2020 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

namespace MikoPBX\Core\System\Configs;


use MikoPBX\Core\System\MikoPBXConfig;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use Phalcon\Di\Injectable;

class SSHConf extends Injectable
{
    private MikoPBXConfig $mikoPBXConfig;

    /**
     * SSHConf constructor.
     */
    public function __construct()
    {
        $this->mikoPBXConfig = new MikoPBXConfig();
    }

    /**
     * Configure SSH settings
     **/
    public function configure()
    {
        $lofFile = '/var/log/lastlog';
        if(!file_exists($lofFile)){
            file_put_contents($lofFile, '');
        }
        $dropbear_dir = '/etc/dropbear';
        Util::mwMkdir($dropbear_dir);

        $keytypes = [
            "rsa"   => "SSHRsaKey",
            "dss"   => "SSHDssKey",
            "ecdsa" => "SSHecdsaKey" // SSHecdsaKey // SSHEcdsaKey
        ];
        // Get keys from DB
        $dropbearkeyPath = Util::which('dropbearkey');
        $dropbearPath = Util::which('dropbear');
        foreach ($keytypes as $keytype => $db_key) {
            $res_keyfilepath = "{$dropbear_dir}/dropbear_" . $keytype . "_host_key";
            $key             = $this->mikoPBXConfig->getGeneralSettings($db_key);
            $key             = (isset($key) && is_string($key)) ? trim($key) : "";
            if (strlen($key) > 100) {
                // Store key to file
                file_put_contents($res_keyfilepath, base64_decode($key));
            }
            // If key not exists, we will generate and store new one into file and database
            if ( ! file_exists($res_keyfilepath)) {
                // Generation
                Processes::mwExec("{$dropbearkeyPath} -t $keytype -f $res_keyfilepath");
                // Storing
                $new_key = base64_encode(file_get_contents($res_keyfilepath));
                $this->mikoPBXConfig->setGeneralSettings("$db_key", "$new_key");
            }
        }
        $ssh_port = escapeshellcmd($this->mikoPBXConfig->getGeneralSettings('SSHPort'));
        // Restart dropbear
        Processes::killByName('dropbear');
        usleep(500000);
        Processes::mwExec("{$dropbearPath} -p '{$ssh_port}' -c /etc/rc/hello > /var/log/dropbear_start.log");
        $this->generateAuthorizedKeys();
        $this->updateShellPassword();
    }

    /**
     * Stores authorized_keys from DB to files
     */
    public function generateAuthorizedKeys(): void
    {
        $ssh_dir = '/root/.ssh';
        Util::mwMkdir($ssh_dir);
        $conf_data = $this->mikoPBXConfig->getGeneralSettings('SSHAuthorizedKeys');
        file_put_contents("{$ssh_dir}/authorized_keys", $conf_data);
    }

    /**
     * Setups root user password
     **/
    public function updateShellPassword(): void
    {
        $password = $this->mikoPBXConfig->getGeneralSettings('SSHPassword');
        $echoPath = Util::which('echo');
        $chpasswdPath = Util::which('chpasswd');
        Processes::mwExec("{$echoPath} \"root:$password\" | {$chpasswdPath}");
    }
}