<?php

/*
    This file is part of TRC Ninja.
    https://github.com/terracoin/trcninja-ctl

    TRC Ninja is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    TRC Ninja is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with TRC Ninja.  If not, see <http://www.gnu.org/licenses/>.

 */

if (!defined('TMN_SCRIPT') || !defined('TMN_CONFIG') || (TMN_SCRIPT !== true) || (TMN_CONFIG !== true)) {
  die('Not executable');
}

DEFINE('TMN_VERSION','2.7.1');

function tmnpidcmp($a, $b)
{
    return strcmp($a['uname'],$b['uname']);
}

function tmn_finduname($tmnpid,$uname) {

  $res = false;
  $x = 0;
  while (($x < count($tmnpid)) && (!$res)) {
    if ($tmnpid[$x]['uname'] == $uname) {
      $res = true;
    }
    $x++;
  }
  return $res;
}

function tmn_getcountry($mnip,&$countrycode) {

  $mnipalone = substr($mnip,0,strpos($mnip,":"));
  $res = geoip_country_name_by_name($mnipalone);
  $countrycode = strtolower(geoip_country_code_by_name($mnipalone));
  return $res;

}

function tmn_getip($pid,$uname) {

  $res = false;
  exec('netstat -ntpl | grep "tcp  " | egrep ":[13333|18321]" | grep "'.$pid.'/terracoind"',$output,$retval);
  if (isset($output[0])) {
    if (preg_match("/tcp        0      0 (\d*\.\d*.\d*.\d*:\d*)/", $output[0], $output_array) == 1) {
      $res = $output_array[1];
    }
  }
  return $res;

}

function tmn_getpayout($mncount,$height) {

  $res = 20.0;
  $halvings = $height / 1050000;
  $res = $res >>= $halvings;
  $res = ($res*720.0*0.45)/$mncount;

  return $res;

}

// Retrieve the PIDs for the hub nodes
function tmn_getpids($nodes,$isstatus = false,$istestnet) {
  if ($isstatus) {
    $semfnam = ($istestnet?TMN_CTLSTATUSAUTO_TEST_SEMAPHORE:TMN_CTLSTATUSAUTO_MAIN_SEMAPHORE);
    if (file_exists($semfnam) && (posix_getpgid(intval(file_get_contents($semfnam))) !== false) ) {
      xecho("Already running (PID ".sprintf('%d',file_get_contents($semfnam)).")\n");
      die(10);
    }
    file_put_contents($semfnam,sprintf('%s',getmypid()));
  }

  $tmnpid = array();

  foreach($nodes as $uname => $node) {
    if (intval($node["NodeTestNet"]) == $istestnet) {
        if (is_dir(TMN_PID_PATH . $uname)) {
            $conf = new TerracoinConfig($uname);
            if ($conf->isConfigLoaded()) {
                if ($node['NodeTestNet'] != $conf->getconfig('testnet')) {
                    xecho("$uname: Configuration inconsistency (testnet/" . $node['NodeTestNet'] . "/" . $conf->getconfig('testnet') . ")\n");
                }
                #if ($node['NodeEnabled'] != $conf->getmnctlconfig('enable')) {
                #    xecho("$uname: Configuration inconsistency (enable/" . $node['NodeEnabled'] . "/" . $conf->getmnctlconfig('enable') . ")\n");
                #}
                $pid = tmn_getpid($uname, ($conf->getconfig('testnet') == '1'));
                $tmnpiditem = array('pid' => $pid,
                    'uname' => $uname,
                    'conf' => $conf,
                    'type' => $node['NodeType'],
                    'enabled' => ($node['NodeEnabled'] == 1),
                    'testnet' => ($node['NodeTestNet'] == 1),
                    'terracoind' => $node['VersionPath'],
                    'currentbin' => '',
                    'keeprunning' => ($node['KeepRunning'] == 1),
                    'keepuptodate' => ($node['KeepUpToDate'] == 1),
                    'versionraw' => $node['VersionRaw'],
                    'versiondisplay' => $node['VersionDisplay'],
                    'versionhandling' => $node['VersionHandling']);
                if ($pid !== false) {
                    if (file_exists('/proc/' . $pid . '/exe')) {
                        $currentbin = readlink('/proc/' . $pid . '/exe');
                        $tmnpiditem['currentbin'] = $currentbin;
                        if ($currentbin != $node['VersionPath']) {
                            xecho("$uname: Binary mismatch ($currentbin != " . $node['VersionPath'] . ")");
                            /*              if ($tmnpiditem['keepuptodate']) {
                                            echo " [Restarting to fix]\n";
                                            tmn_startstop(array($tmnpiditem),"restart",($node['NodeTestNet'] == 1),$node['NodeType']);
                                            sleep(3);
                                            $pid = tmn_getpid($uname,($conf->getconfig('testnet') == '1'));
                                            $tmnpiditem['pid'] = $pid;
                                            if (($pid !== false) && (file_exists('/proc/'.$pid.'/exe'))) {
                                              $currentbin = readlink('/proc/'.$pid.'/exe');
                                              $tmnpiditem['currentbin'] = $currentbin;
                                              if ($currentbin != $node['VersionPath']) {
                                                xecho("$uname: Binary mismatch ($currentbin != ".$node['VersionPath'].") [Restart failed, need admin]\n");
                                              }
                                            }
                                          }
                                          else {  */
                            echo " [Restart to fix]\n";
//              }
                        }
                    } else {
                        xecho("$uname: process ID $pid has no binary information (crashed?)\n");
                    }
                } else {
                    xecho("$uname: process ID not found\n");
                }
                $tmnpid[] = $tmnpiditem;
            }
        }
    }
  }

  usort($tmnpid,"tmnpidcmp");

  return $tmnpid;

}

function tmn_getstatus($terracoindinfo,$blockhash) {

  $res = array('version' => false,
               'protocol' => false,
               'blocks' => 0,
               'connections' => 0,
               'difficulty' => false,
               'encryptedwallet' => false,
               'blockhash' => $blockhash,
               'testnet' => 0);

  if ($terracoindinfo !== false) {
    if (array_key_exists('version',$terracoindinfo)) {
      $res['version'] = $terracoindinfo['version'];
    }
    if (array_key_exists('protocolversion',$terracoindinfo)) {
      $res['protocol'] = $terracoindinfo['protocolversion'];
    }
    if (array_key_exists('difficulty',$terracoindinfo)) {
      $res['difficulty'] = $terracoindinfo['difficulty'];
    }
    if (array_key_exists('blocks',$terracoindinfo)) {
      $res['blocks'] = $terracoindinfo['blocks'];
    }
    if (array_key_exists('connections',$terracoindinfo)) {
      $res['connections'] = $terracoindinfo['connections'];
    }
    if (array_key_exists('testnet',$terracoindinfo)
     && $terracoindinfo['testnet']) {
      $res['testnet'] = 1;
    }
    $res['encryptedwallet'] = array_key_exists('unlocked_until',$terracoindinfo);
  }
  return $res;

}

function tmn_ismasternodeactive($ip,$masternodeinfo,&$listedinactive) {

  $res = false;
  $listedinactive = false;
  if ($masternodeinfo !== false) {
    $listedinactive = array_key_exists($ip,$masternodeinfo) && ($masternodeinfo[$ip] == 0);
    $res = array_key_exists($ip,$masternodeinfo) && ($masternodeinfo[$ip] == 1);
  }
  return $res;

}

// Execute RPC commands
function tmn_ctlrpc(&$commands) {

  $cip = 0;
  $param = '';
  $descriptorspec = array(
      0 => array("pipe", "r"),
      1 => array("pipe", "w"),
      2 => array("pipe", "a")
  );
  $threads = array();
  $pipes = array();
  $thid = 0;
  $commandsdone = 0;
  $done = 0;
  $lastdonetime = time();
  $lastdone = -1;
  $inittime = microtime(true);
  $nbpad = strlen(count($commands));
  $nbok = 0;
  $nberr = 0;

  xecho("Executing ".count($commands)." RPC commands (using ".TMN_THREADS_MAX." threads):\n");

  while ($done != count($commands)) {

    // Check if finished threads
    // If finished set the status of the command to 1 "Almost done"
    $oldthreads = $threads;
    $threads = array();
    foreach($oldthreads as $thread) {
      $info = proc_get_status($thread['res']);
      if (!$info['running']) {
        $cid = $thread['cid'];
        $commands[$cid]['status'] = 1;
        fclose($pipes[$cid][0]);
        $output = stream_get_contents($pipes[$cid][1]);
        if ($info['exitcode'] != 0) {
          $commands[$cid]['result'] = $output;
          $commands[$cid]['status'] = -1;
          $nberr++;
        }
        else {
          $commands[$cid]['status'] = 2;
          $nbok++;
        }
        fclose($pipes[$cid][1]);
        fclose($pipes[$cid][2]);
        proc_close($thread['res']);
        $done++;
      }
      else {
        $threads[] = $thread;
      }
    }

    // Fill up free threads with all possible commands
    // Execute the command in a thread
    while ((count($threads) < TMN_THREADS_MAX) && ($commandsdone < count($commands))) {
      $pipes[$commandsdone] = array();
      $thres[$commandsdone] = proc_open('timeout 10 '.TMN_DIR.'/tmnctlrpc '.$commands[$commandsdone]['cmd'].' '.$commands[$commandsdone]['file'],$descriptorspec,$pipes[$commandsdone]);
      if (is_resource($thres[$commandsdone])) {
        $threads[] = array('cid' => $commandsdone, 'res' => $thres[$commandsdone]);
        $commandsdone++;
      }
    }
    if (($lastdone != $done) && (time() > $lastdonetime)) {
      xecho(" (".str_pad(round(($done/count($commands))*100,0),3," ",STR_PAD_LEFT)."% - ".str_pad($done,$nbpad," ",STR_PAD_LEFT)."/".count($commands).") In progress...\n");
      $lastdone = $done;
      $lastdonetime = time();
    }
    // Do a 100ms pause
    usleep(100000);
  }

  xecho(" (100% - ".count($commands)."/".count($commands).") Done in ".round(microtime(true)-$inittime,3)." seconds [$nbok sucessfully/$nberr with errors]\n");

}

// Execute start-stop command multi-threaded
function tmn_ctlstartstop(&$commands) {

  $descriptorspec = array(
      0 => array("pipe", "r"),
      1 => array("pipe", "w"),
      2 => array("pipe", "a")
  );
  $threads = array();
  $pipes = array();
  $commandsdone = 0;
  $done = 0;
  $lastdonetime = time();
  $lastdone = -1;
  $inittime = microtime(true);
  $nbpad = strlen(count($commands));
  $nbok = 0;
  $nberr = 0;

  xecho("Executing ".count($commands)." start-stop commands (using ".TMN_THREADS_MAX." threads):\n");

  while ($done != count($commands)) {

    // Check if finished threads
    // If finished set the status of the command to 1 "Almost done"
    $oldthreads = $threads;
    $threads = array();
    foreach($oldthreads as $thread) {
      $info = proc_get_status($thread['res']);
      if (!$info['running']) {
        $cid = $thread['cid'];
        $commands[$cid]['status'] = 1;
        fclose($pipes[$cid][0]);
        $output = stream_get_contents($pipes[$cid][1]);
        $commands[$cid]['output'] = $output;
        $commands[$cid]['exitcode'] = $info['exitcode'];
        if ($info['exitcode'] != 0) {
          $commands[$cid]['status'] = -1;
          $nberr++;
        }
        else {
          $commands[$cid]['status'] = 2;
          $nbok++;
        }
        fclose($pipes[$cid][1]);
        fclose($pipes[$cid][2]);
        proc_close($thread['res']);
        $done++;
      }
      else {
        $threads[] = $thread;
      }
    }

    // Fill up free threads with all possible commands
    // Execute the command in a thread
    while ((count($threads) < TMN_THREADS_MAX) && ($commandsdone < count($commands))) {
      $pipes[$commandsdone] = array();
      $thres[$commandsdone] = proc_open(TMN_DIR.'/tmnctlstartstopdaemon '.$commands[$commandsdone]['cmd'],$descriptorspec,$pipes[$commandsdone]);
      if (is_resource($thres[$commandsdone])) {
        $threads[] = array('cid' => $commandsdone, 'res' => $thres[$commandsdone]);
        $commandsdone++;
      }
    }
    if (($lastdone != $done) && (time() > $lastdonetime)) {
      xecho(" (".str_pad(round(($done/count($commands))*100,0),3," ",STR_PAD_LEFT)."% - ".str_pad($done,$nbpad," ",STR_PAD_LEFT)."/".count($commands).") In progress...\n");
      $lastdone = $done;
      $lastdonetime = time();
    }
    // Do a 100ms pause
    usleep(100000);
  }

  xecho(" (100% - ".count($commands)."/".count($commands).") Done in ".round(microtime(true)-$inittime,3)." seconds [$nbok sucessfully/$nberr with errors]\n");

}

//#############################################################################
//#############################################################################
//
//                             ACTIONS FUNCTIONS
//
//#############################################################################
//#############################################################################

// Show usage of the script
function tmn_help($exename) {

  $exename = basename($exename);
  echo "Usage: $exename action [option1] [option2] [..] [optionX]\n";
  echo "Action         Description                      Expected parameters\n";
  echo "address        Set masternode address           option1 = Address, option2 = Masternode\n";
  echo "create         Creates the masternode (user)    option1 = external IP to use\n";
  echo "disable        Disable masternode(s)            List of masternodes (ex: tmn03 tmn04)\n";
  echo "enable         Enable masternode(s)             List of masternodes (ex: tmn03 tmn04)\n";
  echo "status         Retrieve masternodes status      None or option1=html for HTML output\n";
  echo "                                                option2=None or private (for no sensitive info)\n";
  echo "\n";
  echo "start          Starts nodes                     option1 = testnet|mainnet, option2 = all|masternode|p2pool\n";
  echo "restart        Restarts nodes                   option1 = testnet|mainnet, option2 = all|masternode|p2pool\n";
  echo "stop           Stop nodes                       option1 = testnet|mainnet, option2 = all|masternode|p2pool\n";
  echo "\n";
  echo "version        Create a new terracoind version       option1 = binary path\n";
  echo "                                                option2 = display string\n";
  echo "                                                option3 = testnet only (1 or 0)\n";
  echo "                                                option4 = enabled (1 or 0)\n";
}

// Create a new terracoind version in the database usable by nodes
function tmn_version_create($versionpath, $versiondisplay, $testnet, $enabled) {

  xecho("Retrieving raw version number from binary: ");
  $versionraw = tmn_terracoindversion($versionpath);
  if ($versionraw !== false) {
    echo "OK ($versionraw)\n";
    chmod($versionpath,0755);
    xecho("Retrieving binary information: ");
    $versionsize = filesize($versionpath);
    echo $versionsize." bytes... ";
    $versionhash = sha1(file_get_contents($versionpath));
    echo "OK (SHA1=$versionhash)\n";
    xecho("Compressing version");
    exec("/usr/bin/xz -zk $versionpath", $output, $res);
    if ($res != 0) {
      echo "Error ($res)\n";
      die(7);
    }
    $versionpathcomp = $versionpath.'.xz';
    echo "Done (".filesize($versionpathcomp)." bytes)\n";
    $versionurl = basename($versionpathcomp);
    $versionpathcompdir = TMN_CDL_DIR.$versionurl;
    $versionurl = TMN_CDL_URL.$versionurl;
    xecho("Moving $versionpathcomp to $versionpathcompdir: ");
    if (rename($versionpathcomp,$versionpathcompdir)) {
      echo "OK\n";
    }
    else {
      echo "Error (Failed to move)\n";
    }
    if ((substr($versionraw,0,7) == '0.12.1.') || (substr($versionraw,0,7) == '0.12.2.')) {
      $versionhandling = 4;
    }
    elseif (substr($versionraw,0,5) == '0.12.') {
      $versionhandling = 3;
    }
    else {
      $versionhandling = 2;
    }
    xecho("Submitting new version to webservice: ");
    $payload = array('VersionPath' => $versionpath,
        'VersionRaw' => $versionraw,
        'VersionDisplay' => $versiondisplay,
        'VersionTestnet' => $testnet,
        'VersionEnabled' => $enabled,
        'VersionURL' => $versionurl,
        'VersionHash' => $versionhash,
        'VersionSize' => $versionsize,
        'VersionHandling' => $versionhandling);
    $content = tmn_cmd_post('/versions',$payload,$response);
    if (strlen($content) > 0) {
      $content = json_decode($content,true);
      if (($response['http_code'] >= 200) && ($response['http_code'] <= 299)) {
        echo "Success (".$content['data']['VersionId'].")\n";
      }
      else {
        echo "Error (".$response['http_code'].": ".print_r($content['messages'],true).")\n";
      }
    }
    else {
      echo "Error (empty result) [HTTP CODE ".$response['http_code']."]\n";
    }
  }
  else {
    echo "8\n\n";
    echo "Error\n";
    die(1);
  }

}

// Create a new Terracoin Masternode user, prepare folder and configuration
function tmn_create($tmnpid,$ip,$forcename = '') {

  if ($forcename == '') {
    echo "Forcing $forcename: ";
    $newnum = intval(substr($tmnpid[count($tmnpid)-1]['uname'],5,2))+1;
    $newuname = TMN_PID_PREFIX.str_pad($newnum,2,'0',STR_PAD_LEFT);
  }
  else {
    $newuname = $forcename;
    $newnum = intval(substr($forcename,-2));
  }
  if (TMNTESTNET === true) {
    $testinfo = ' Testnet';
  }
  else {
    $testinfo = '';
  }
  echo "Creating $newuname: ";
  exec('useradd -m -c "Terracoin$testinfo MasterNode #'.$newnum.'" -U -s /bin/false -p '.randomPassword(128).' '.$newuname.' 1>/dev/null 2>/dev/null',$output,$retval);
  if ($retval != 0) {
    echo "Already exists!\n";
    if ($forcename == '') {
      die;
    }
  }
  else {
    echo "retval=$retval\n";
  }
  echo "Generating terracoin.conf";
  mkdir("/home/$newuname/.terracoincore");
  touch("/home/$newuname/.terracoincore/terracoin.conf");
  chmod("/home/$newuname/.terracoincore",0700);
  chmod("/home/$newuname/.terracoincore/terracoin.conf",0600);
  $conflist = array('server=1',
         'rpcuser='.$newuname.'rpc',
         'rpcpassword='.randomPassword(128),
         'alertnotify=echo %s | mail -s "Terracoin MasterNode #'.str_pad($newnum,2,'0',STR_PAD_LEFT).' Alert" somebody@mowhere.blackhole',
         'rpcallowip=127.0.0.1',
         "bind=$ip",
         'rpcport='.(intval($newnum)+TMNCTLRPCPORTVAL).'998',
         'masternode=0',
         "externalip=$ip",
         '#mnctlcfg#enable=1');
  if (TMNTESTNET === true) {
    $conflist[] = 'testnet=1';
  }

  $terracoinconf = implode("\n",$conflist);
  file_put_contents("/home/$newuname/.terracoincore/terracoin.conf",$terracoinconf);
  echo "OK\n";
  echo "Setting ACL";
  if (file_exists("/home/$newuname/.bash_history")) {
    chmod("/home/$newuname/.bash_history",0600);
  }
  chmod("/home/$newuname/.bashrc",0600);
  chmod("/home/$newuname/.profile",0600);
  chmod("/home/$newuname/.bash_logout",0600);
  chmod("/home/$newuname/",0700);
  chown("/home/$newuname/.terracoincore/",$newuname);
  chgrp("/home/$newuname/.terracoincore/",$newuname);
  chown("/home/$newuname/.terracoincore/terracoin.conf",$newuname);
  chgrp("/home/$newuname/.terracoincore/terracoin.conf",$newuname);
  echo "OK\n";
  echo "Add to /etc/network/interfaces\n";
  echo "        post-up /sbin/ifconfig eth0:$newnum $ip netmask 255.255.255.255 broadcast $ip\n";
  echo "        post-down /sbin/ifconfig eth0:$newnum down\n";

}

// Set the enable flag to 0 in terracoin.conf to disable the Masternode
function tmn_disable($tmnpid,$tmntodisable) {
  foreach ($tmntodisable as $uname) {
    echo "Disabling $uname: ";
    if (tmn_finduname($tmnpid,$uname)) {
      $conf = new TerracoinConfig($uname);
      if (($conf->getmnctlconfig('enable') == 0) && ($conf->getmnctlconfig('enable') !== false)) {
        echo "Already disabled";
      }
      else {
        $conf->setmnctlconfig('enable',0);
        if ($conf->saveconfig() !== false) {
          echo "Done";
        }
        else {
          echo "Failed";
        }
      }
    }
    else {
      echo "Unknown Terracoin MasterNode";
    }
    echo "\n";
  }
}

// Set the enable flag to 1 in terracoin.conf to enable the Masternode
function tmn_enable($tmnpid,$tmntoenable) {
  foreach ($tmntoenable as $uname) {
    echo "Enabling $uname: ";
    if (tmn_finduname($tmnpid,$uname)) {
      $conf = new TerracoinConfig($uname);
      if ($conf->getmnctlconfig('enable') == 1) {
        echo "Already enabled";
      }
      else {
        $conf->setmnctlconfig('enable',1);
        if ($conf->saveconfig() !== false) {
          echo "Done";
        }
        else {
          echo "Failed";
        }
      }
    }
    else {
      echo "Unknown Terracoin MasterNode";
    }
    echo "\n";
  }
}

// Start/Stop/Restart nodes
// $todo can be "start", "stop" or "restart"
// If $testnet is true then only start testnet (else start mainnet)
// $nodetype can be "p2pool" or "masternode"
function tmn_startstop($tmnpid,$todo,$testnet = false,$nodetype = 'masternode',$withreindex = false) {

  $nodes = array();
  foreach($tmnpid as $node) {
    if (($node['testnet'] == $testnet)
     && ($node['type'] == $nodetype)
     && ($node['enabled'])) {
      $nodes[] = $node;
    }
  }

  if ($todo == 'start') {
    xecho("Starting ");
  }
  elseif ($todo == 'stop') {
    xecho("Stopping ");
  }
  elseif ($todo == 'restart') {
    xecho("Restarting ");
  }
  else {
    xecho("Unknown command $todo. Terminated.\n");
    die();
  }

  $extra = "";
  if ($withreindex) {
    echo "with -reindex ";
    $extra = " -reindex";
  }
  echo count($nodes)." nodes:\n";

  $commands = array();
  foreach($nodes as $nodenum => $node) {
    $uname = $node['uname'];
    $commands[] = array("status" => 0,
                        "nodenum" => $nodenum,
                        "cmd" => "$uname $todo ".$node['terracoind'].$extra,
                        "exitcode" => -1,
                        "output" => '');
  }
  tmn_ctlstartstop($commands);

  foreach($commands as $command) {
    echo $command['output'];
  }

}

// Start all KeepRunning nodes
// If $testnet is true then only start testnet (else start mainnet)
function tmn_startkeeprunning($tmnpid) {

  $nodes = array();
  foreach($tmnpid as $node) {
    if ($node['keeprunning']) {
      $nodes[] = $node;
    }
  }

  xecho("Keep Running ".count($nodes)." nodes:\n");

  $commands = array();
  foreach($nodes as $nodenum => $node) {
    $uname = $node['uname'];
    $commands[] = array("status" => 0,
        "nodenum" => $nodenum,
        "cmd" => "$uname start ".$node['terracoind'],
        "exitcode" => -1,
        "output" => '');
  }
  tmn_ctlstartstop($commands);

  foreach($commands as $command) {
    echo $command['output'];
  }

}

// Restart frozen nodes
function tmn_restartfrozen($tmnpid) {

  xecho("Dealing with ");
  echo count($tmnpid)." frozen nodes:\n";

  $commands = array();
  $commands2 = array();
  foreach($tmnpid as $nodenum => $node) {
    $uname = $node['uname'];
    if (file_exists("/tmp/tmnctl-NR-$uname-counter")) {
      $counter = intval(file_get_contents("/tmp/tmnctl-NR-$uname-counter"));
      $counter++;
    }
    else {
      $counter = 1;
    }
    xechoToFile(TMN_NRCOUNTLOG,"Unresponsive ".$uname." counter ".$counter);
    if ($node["testnet"]) {
      $maxcount = TMN_NRCOUNT_TEST;
    }
    else {
      $maxcount = TMN_NRCOUNT;
    }
    if ($counter >= $maxcount) {
      unlink("/tmp/tmnctl-NR-$uname-counter",$counter);
      $commands[] = array("status" => 0,
          "nodenum" => $nodenum,
          "cmd" => "$uname stop " . $node['terracoind'],
          "exitcode" => -1,
          "output" => '');
      if ($node["keeprunning"]) {
        $commands2[] = array("status" => 0,
            "nodenum" => $nodenum,
            "cmd" => "$uname start " . $node['terracoind'],
            "exitcode" => -1,
            "output" => '');
        xechoToFile(TMN_NRCOUNTLOG,"Restarting unresponsive ".$uname);
      }
      else {
        xechoToFile(TMN_NRCOUNTLOG,"Stopping unresponsive ".$uname);
      }
    }
    else {
      file_put_contents("/tmp/tmnctl-NR-$uname-counter",$counter);
    }
  }
  tmn_ctlstartstop($commands);
  foreach($commands as $command) {
    echo $command['output'];
  }
  if (count($commands2) > 0) {
    tmn_ctlstartstop($commands2);
    foreach ($commands2 as $command) {
      echo $command['output'];
    }
  }

}

// Display masternode status and submit statistics to private API
function tmn_status($tmnpid,$istestnet) {

  $mninfolast = array();

  $mnlistfinal = array();
  $mnlist2final = array();
  $mnlastseen = array();
  $mnactivesince = array();
  $mnpubkeylistfinal = array();
  $difficultyfinal = 0;
  $daemonactive = array();
  $protocolinfo = array();
  $curprotocol = 0;
  $oldprotocol = 99999;
  $mnstatusexvalues = array('ENABLED','EXPIRED','VIN_SPENT','REMOVE','POS_ERROR','','PRE_ENABLED','WATCHDOG_EXPIRED','NEW_START_REQUIRED','UPDATE_REQUIRED');

  $wsstatus = array();

  $netstr = "main";
  if ($istestnet == 1) {
    $netstr = "test";
  }
  $netstr.="net";

  xecho('Retrieving status for '.count($tmnpid)." $netstr nodes\n");

  if (!is_dir("/dev/shm/tmnctl")) {
    if (!mkdir("/dev/shm/tmnctl")) {
      echo "Failed to create directory.\n";
      die(100);
    }
  }

  $tmpdate = date('YmdHis');
  $commands = array();

  // First check the pid and getinfo for all nodes
  foreach($tmnpid as $tmnnum => $tmnpidinfo) {
    $uname = $tmnpidinfo['uname'];
    $tmnpid[$tmnnum]['pidstatus'] = tmn_checkpid($tmnpidinfo['pid']);
    if (($tmnpid[$tmnnum]['pidstatus']) && ($tmnpidinfo['currentbin'] != '')) {
      $commands[] = array("status" => 0,
                          "tmnnum" => $tmnnum,
                          "datatype" => "info",
                          "cmd" => "$uname getinfo",
                          "file" => "/dev/shm/tmnctl/$uname.$tmpdate.getinfo.json");
    }
  }

  foreach($tmnpid as $tmnnum => $tmnpidinfo) {
    $uname = $tmnpidinfo['uname'];
    // Only vh 3+
    if (($tmnpidinfo['pidstatus']) && ($tmnpidinfo['currentbin'] != '') && ($tmnpidinfo['versionhandling'] >= 3)) {
      $commands[] = array("status" => 0,
                          "tmnnum" => $tmnnum,
                          "datatype" => "mnlistfull",
                          "cmd" => $uname.' "masternode list full"',
                          "file" => "/dev/shm/tmnctl/$uname.$tmpdate.masternode_list.json");
      // v12.1 (vh=4)
      if ($tmnpidinfo['versionhandling'] >= 4) {
        $commands[] = array("status" => 0,
            "tmnnum" => $tmnnum,
            "datatype" => "gobjectlist",
            "cmd" => $uname . ' "gobject list"',
            "file" => "/dev/shm/tmnctl/$uname.$tmpdate.gobject_list.json");
          $commands[] = array("status" => 0,
              "tmnnum" => $tmnnum,
              "datatype" => "getgovernanceinfo",
              "cmd" => $uname . ' getgovernanceinfo',
              "file" => "/dev/shm/tmnctl/$uname.$tmpdate.getgovernanceinfo.json");
      }
      // v12.0 (vh=3)
      else {
        $commands[] = array("status" => 0,
            "tmnnum" => $tmnnum,
            "datatype" => "mnbudgetshow",
            "cmd" => $uname . ' "mnbudget show"',
            "file" => "/dev/shm/tmnctl/$uname.$tmpdate.mnbudget_show.json");
        $commands[] = array("status" => 0,
            "tmnnum" => $tmnnum,
            "datatype" => "mnbudgetfinal",
            "cmd" => $uname.' "mnfinalbudget show"',
            "file" => "/dev/shm/tmnctl/$uname.$tmpdate.mnfinalbudget_show.json");
        $commands[] = array("status" => 0,
            "tmnnum" => $tmnnum,
            "datatype" => "mnbudgetprojection",
            "cmd" => $uname.' "mnbudget projection"',
            "file" => "/dev/shm/tmnctl/$uname.$tmpdate.mnbudget_projection.json");
      }
    }
    // Only vh 2 and below
    if (($tmnpidinfo['pidstatus']) && ($tmnpidinfo['currentbin'] != '') && ($tmnpidinfo['versionhandling'] <= 2)) {
      $commands[] = array("status" => 0,
                          "tmnnum" => $tmnnum,
                          "datatype" => "mnlist",
                          "cmd" => $uname.' "masternode list"',
                          "file" => "/dev/shm/tmnctl/$uname.$tmpdate.masternode_list.json");
      $commands[] = array("status" => 0,
                          "tmnnum" => $tmnnum,
                          "datatype" => "mndonation",
                          "cmd" => $uname.' "masternode list donation"',
                          "file" => "/dev/shm/tmnctl/$uname.$tmpdate.masternode_list_donation.json");
      $commands[] = array("status" => 0,
                          "tmnnum" => $tmnnum,
                          "datatype" => "mnvotes",
                          "cmd" => $uname.' "masternode list votes"',
                          "file" => "/dev/shm/tmnctl/$uname.$tmpdate.masternode_list_votes.json");
      $commands[] = array("status" => 0,
                          "tmnnum" => $tmnnum,
                          "datatype" => "mnlastseen",
                          "cmd" => $uname.' "masternode list lastseen"',
                          "file" => "/dev/shm/tmnctl/$uname.$tmpdate.masternode_list_lastseen.json");
      $commands[] = array("status" => 0,
                          "tmnnum" => $tmnnum,
                          "datatype" => "mnpubkey",
                          "cmd" => $uname.' "masternode list pubkey"',
                          "file" => "/dev/shm/tmnctl/$uname.$tmpdate.masternode_list_pubkey.json");
      $commands[] = array("status" => 0,
                          "tmnnum" => $tmnnum,
                          "datatype" => "mnpose",
                          "cmd" => $uname.' "masternode list pose"',
                          "file" => "/dev/shm/tmnctl/$uname.$tmpdate.masternode_list_pose.json");
      $commands[] = array("status" => 0,
                          "tmnnum" => $tmnnum,
                          "datatype" => "mnactiveseconds",
                          "cmd" => $uname.' "masternode list activeseconds"',
                          "file" => "/dev/shm/tmnctl/$uname.$tmpdate.masternode_list_activeseconds.json");
    }
  }

  // All vh
  foreach($tmnpid as $tmnnum => $tmnpidinfo) {
    $uname = $tmnpidinfo['uname'];
    if (($tmnpidinfo['pidstatus']) && ($tmnpidinfo['currentbin'] != '')) {
      $commands[] = array("status" => 0,
                          "tmnnum" => $tmnnum,
                          "datatype" => "mncurrent",
                          "cmd" => $uname.' "masternode current"',
                          "file" => "/dev/shm/tmnctl/$uname.$tmpdate.masternode_current.json");
      $commands[] = array("status" => 0,
                          "tmnnum" => $tmnnum,
                          "datatype" => "spork",
                          "cmd" => $uname.' "spork show"',
                          "file" => "/dev/shm/tmnctl/$uname.$tmpdate.spork_show.json");
    }
  }

  tmn_ctlrpc($commands);

  xecho("Parsing results...\n");
  foreach($commands as $command) {
    if ($command['status'] != 2) {
      $res = false;
      xecho("Command failed (".$command['cmd'].") [".$command['result']."]\n");
    }
    else {
      $res = file_get_contents($command['file']);
      if ($res !== false) {
        if ($command['datatype'] == 'mnpubkey') {
          $res = explode(",",substr($res,1,-1));
          $pubkeys = array();
          foreach($res as $line) {
            $raw = explode(":",$line);
            if (is_array($raw) && (count($raw) == 3)) {
              $ip = substr(trim($raw[0]),1);
              $port = substr(trim($raw[1]),0,-1);
              $pubkey = substr(trim($raw[2]),1,-1);
              $pubkeys[] = array("ip" => $ip, "port" => $port, "pubkey" => $pubkey);
            }
          }
          $res = $pubkeys;
        }
        elseif ($command['datatype'] == 'mndonation') {
          $res = explode(",",substr($res,1,-1));
          $pubkeys = array();
          foreach($res as $line) {
            $raw = explode(":",$line);
            if (is_array($raw)) {
              if (count($raw) == 4) {
                $ip = substr(trim($raw[0]),1);
                $port = substr(trim($raw[1]),0,-1);
                $pubkey = substr(trim($raw[2]),1);
                $percent = substr(trim($raw[3]),0,-1);
                $pubkeys[] = array("ip" => $ip, "port" => $port, "pubkey" => $pubkey, "percent" => intval($percent));
              }
              elseif (count($raw) == 3) {
                $ip = substr(trim($raw[0]),1);
                $port = substr(trim($raw[1]),0,-1);
                $pubkey = substr(trim($raw[2]),1);
                $pubkeys[] = array("ip" => $ip, "port" => $port, "pubkey" => '', "percent" => 0);
              }
            }
          }
          $res = $pubkeys;
        }
        elseif ($command['datatype'] != 'mncurrent') {
          $res = json_decode($res,true);
          if ($res === false) {
            xecho("Could not decode JSON from ".$command['file']."\n");
          }
          if (array_key_exists('result',$res)) {
            $res = $res['result'];
          }
        }
      }
      else {
        xecho("Could not read file: ".$command['file']."\n");
      }
      if (!unlink($command['file'])) {
        xecho("Could not delete file: ".$command['file']."\n");
      }
    }
    $tmnpid[$command['tmnnum']][$command['datatype']] = $res;
  }

  $commands = array();
  $nbuname = 5;
  $nbversion = 7;
  $nbprotocol = 8;
  $nbblocks = 6;
  $nbconnections = 4;
  $nbpid = 3;
  foreach($tmnpid as $tmnnum => $tmnpidinfo) {
    $uname = $tmnpidinfo['uname'];
    if (strlen($tmnpidinfo['pid']) > $nbpid) {
      $nbpid = strlen($tmnpidinfo['pid']);
    }
    if (strlen($uname) > $nbuname) {
      $nbuname = strlen($uname);
    }
    if (array_key_exists('info',$tmnpidinfo)) {
      if (strlen($tmnpidinfo['info']['version']) > $nbversion) {
        $nbversion = strlen($tmnpidinfo['info']['version']);
      }
      if (strlen($tmnpidinfo['info']['protocolversion']) > $nbprotocol) {
        $nbprotocol = strlen($tmnpidinfo['info']['protocolversion']);
      }
      if (strlen($tmnpidinfo['info']['blocks']) > $nbblocks) {
        $nbblocks = strlen($tmnpidinfo['info']['blocks']);
      }
      if (strlen($tmnpidinfo['info']['connections']) > $nbconnections) {
        $nbconnections = strlen($tmnpidinfo['info']['connections']);
      }
    }
    if (($tmnpidinfo['pidstatus']) && ($tmnpidinfo['currentbin'] != '')) {
      $commands[] = array("status" => 0,
                          "tmnnum" => $tmnnum,
                          "datatype" => "blockhash",
                          "cmd" => $uname.' "getblockhash '.$tmnpidinfo['info']['blocks'].'"',
                          "file" => "/dev/shm/tmnctl/$uname.$tmpdate.getblockhash.json");
      $commands[] = array("status" => 0,
                          "tmnnum" => $tmnnum,
                          "datatype" => "networkhashps",
                          "cmd" => $uname.' getnetworkhashps',
                          "file" => "/dev/shm/tmnctl/$uname.$tmpdate.getnetworkhashps.json");
      if (($tmnpidinfo['versionhandling'] == 3) && array_key_exists("mnbudgetshow",$tmnpidinfo) && is_array($tmnpidinfo["mnbudgetshow"])) {
        foreach ($tmnpidinfo["mnbudgetshow"] as $mnbudgetid => $mnbudgetdata) {
          $commands[] = array("status" => 0,
              "tmnnum" => $tmnnum,
              "datatype" => "mnbudget-getvotes-" . $mnbudgetid,
              "cmd" => $uname . ' "mnbudget getvotes ' . $mnbudgetid . '"',
              "file" => "/dev/shm/tmnctl/$uname.$tmpdate.mnbudget_getvotes_$mnbudgetid.json");
        }
      }
      elseif ($tmnpidinfo['versionhandling'] == 4) {
        if  (array_key_exists("getgovernanceinfo",$tmnpidinfo) && is_array($tmnpidinfo["getgovernanceinfo"])) {
            $commands[] = array("status" => 0,
                "tmnnum" => $tmnnum,
                "datatype" => "getsuperblockbudget",
                "cmd" => $uname . ' "getsuperblockbudget '.$tmnpidinfo["getgovernanceinfo"]["nextsuperblock"].'"',
                "file" => "/dev/shm/tmnctl/$uname.$tmpdate.getsuperblockbudget.json");
        }
        if  (array_key_exists("gobjectlist",$tmnpidinfo) && is_array($tmnpidinfo["gobjectlist"])) {
          $gobjectproposals = array();
          $gobjecttriggers = array();
          foreach ($tmnpidinfo["gobjectlist"] as $gobjecthash => $gobjectdata) {
            if (is_array($gobjectdata) && array_key_exists("DataString",$gobjectdata)) {
              $gobjectdata2 = json_decode($gobjectdata["DataString"],true);
              if ($gobjectdata2 === false) {
                 xecho("Could not decode JSON from gobject ".$gobjecthash."\n");
              }
              elseif (!is_array($gobjectdata2) || (count($gobjectdata2) != 1) || !is_array($gobjectdata2[0]) || (count($gobjectdata2[0]) != 2)) {
                 xecho("Incorrect JSON from gobject ".$gobjecthash." ".count($gobjectdata2)."\n");
              }
              elseif ($gobjectdata2[0][0] == "proposal") {
                $gobjectdata2[0][1]["hash"] = $gobjecthash;
                $gobjectdata2[0][1]["gobject"] = $gobjectdata;
                unset($gobjectdata2[0][1]["gobject"]["DataHex"],$gobjectdata2[0][1]["gobject"]["DataString"]);
                $gobjectproposals[] = $gobjectdata2[0][1];
                $commands[] = array("status" => 0,
                                    "tmnnum" => $tmnnum,
                                    "datatype" => "gobject-getvotes-" . $gobjecthash,
                                    "cmd" => $uname . ' "gobject getvotes ' . $gobjecthash . '"',
                                    "file" => "/dev/shm/tmnctl/$uname.$tmpdate.gobject_getvotes_$gobjecthash.json");
              }
              elseif ($gobjectdata2[0][0] == "trigger") {
                  $gobjectdata2[0][1]["hash"] = $gobjecthash;
                  $gobjectdata2[0][1]["gobject"] = $gobjectdata;
                  unset($gobjectdata2[0][1]["gobject"]["DataHex"],$gobjectdata2[0][1]["gobject"]["DataString"]);
                  $gobjecttriggers[] = $gobjectdata2[0][1];
                  $commands[] = array("status" => 0,
                      "tmnnum" => $tmnnum,
                      "datatype" => "gobject-getvotes-" . $gobjecthash,
                      "cmd" => $uname . ' "gobject getvotes ' . $gobjecthash . '"',
                      "file" => "/dev/shm/tmnctl/$uname.$tmpdate.gobject_getvotes_$gobjecthash.json");
              }
            }
          }
          $tmnpid[$tmnnum]["gobjectlist"] = array("proposals" => $gobjectproposals, "triggers" => $gobjecttriggers);
      }
      }
    }
  }

  tmn_ctlrpc($commands);

  xecho("Parsing results...\n");
  foreach($commands as $command) {
    if ($command['status'] != 2) {
      $res = false;
    }
    else {
      $res = file_get_contents($command['file']);
      if ($res === false) {
        xecho("Could not read file: ".$command['file']."\n");
      }
      if (!unlink($command['file'])) {
        xecho("Could not delete file: ".$command['file']."\n");
      }
      if (((strlen($command['datatype']) > 18) && (substr($command['datatype'],0,18) == 'mnbudget-getvotes-'))
       || ((strlen($command['datatype']) > 17) && (substr($command['datatype'],0,17) == 'gobject-getvotes-'))) {
        $res = json_decode($res,true);
        if ($res === false) {
          xecho("Could not decode JSON from ".$command['file']."\n");
        }
        if (array_key_exists('result',$res)) {
          $res = $res['result'];
        }
      }
    }
    $tmnpid[$command['tmnnum']][$command['datatype']] = $res;
  }

  xecho(str_pad("Node",$nbuname)." ".str_pad("PID",$nbpid)." ST ".str_pad("Version",$nbversion)." ".str_pad("Protocol",$nbprotocol)." ".str_pad("Blocks",$nbblocks)." ".str_pad("Hash",64)." ".str_pad("Conn",$nbconnections)." V IP\n");
  $separator = str_repeat("-",$nbuname+$nbpid+$nbversion+$nbprotocol+$nbblocks+109)."\n";
  xecho($separator);

  $networkhashps = false;
  $networkhashpstest = false;
  $governancebudget = array(false,false);
  $governancenextsb = array(false,false);

  $spork = array();

  $mninfo2 = array();
  $mnbudgetshow = array();
  $mnbudgetprojection = array(array(),array());
  $mnbudgetfinal = array();
  $mndonationlistfinal = array();
  $mnvoteslistfinal = array();
  $mnbudgetvotes = array(array(),array());
  $gobjectproposallist = array();
  $gobjecttriggerlist = array();
  $gobjectvotes = array(array(),array());
  $tmnpidtorestart = array();

  // Go through all nodes
  foreach($tmnpid as $tmnnum => $tmnpidinfo) {

    // Get the uname
    $uname = $tmnpidinfo['uname'];
    $conf = $tmnpidinfo['conf'];

    // Is the node enabled in the configuration
    $tmnenabled = $tmnpidinfo['enabled'];

    // Get default port
    if ($tmnpidinfo['conf']->getconfig('testnet') == '1') {
      $port = 18321;
    }
    else {
      $port = 13333;
    }

    // Default values
    $iponly = '';
    $version = 0;
    $protocol = 0;
    $blocks = 0;
    $blockhash = '';
    $connections = 0;
    $country = '';
    $countrycode = '';
    $spork[$uname] = array();

    // Indicate what we are doing
    xecho(str_pad($uname,$nbuname)." ".str_pad($tmnpidinfo['pid'],$nbpid,' ',STR_PAD_LEFT)." ");

    // If the process is running
    if (($tmnpidinfo['pid'] !== false) && ($tmnpidinfo['currentbin'] != '')) {

      // Spork info
      if (array_key_exists("spork",$tmnpidinfo)) {
        $spork[$uname] = $tmnpidinfo['spork'];
      }
      else {
        $spork[$uname] = array();
      }

      // Parse status
      $terracoindinfo = tmn_getstatus($tmnpidinfo['info'],$tmnpidinfo['blockhash']);
      $blocks = $terracoindinfo['blocks'];
      $blockhash = $terracoindinfo['blockhash'];
      $connections = $terracoindinfo['connections'];
      $difficulty = $terracoindinfo['difficulty'];
      $protocol = $terracoindinfo['protocol'];
      $version = $terracoindinfo['version'];

      // Protocol
      //  Current protocol is the max protocol
      if ($curprotocol < $protocol) {
        $curprotocol = $protocol;
      }
      //  Old protocol is the min protocol
      if ($oldprotocol > $protocol) {
        $oldprotocol = $protocol;
      }
      //  Store the protocol of this node
      $protocolinfo[$uname] = $protocol;

      // Store the networkhash
      $networkhashps = intval($tmnpidinfo['networkhashps']);

      // If the version could be retrieved
      if ($version !== false) {
        // Our node is active
        $daemonactive[] = $uname;

        // Remove the notresponding counter file
        if (file_exists(TMN_NRCOUNTDIR."tmnctl-NR-$uname-counter")) {
          unlink(TMN_NRCOUNTDIR."tmnctl-NR-$uname-counter");
        }

        // Retrieve the IP from the node
        $ip = tmn_getip($tmnpidinfo['pid'],$uname);
        $tmnip = $ip;
        $ipexp = explode(':',$ip);
        $iponly = $ipexp[0];
        $country = tmn_getcountry($ip,$countrycode);
        if ($country === false) {
          $country = 'Unknown';
          $countrycode = '__';
        }
        $port = $ipexp[1];

        // Default values
        $processstatus = 'running';

        // Display some feedback
        echo "OK ";
        echo str_pad($version,$nbversion,' ',STR_PAD_LEFT)
        ." ".str_pad($protocol,$nbprotocol,' ',STR_PAD_LEFT)
        ." ".str_pad($blocks,$nbblocks,' ',STR_PAD_LEFT)
        ." $blockhash "
        .str_pad($connections,$nbconnections,' ',STR_PAD_LEFT)." ";

        // Store the max difficulty
        if ($difficulty > $difficultyfinal) {
          $difficultyfinal = $difficulty;
        }

        // Indicates what version handling we are using
        echo $tmnpidinfo['versionhandling'];

        // Old version handling (1 & 2)
        if ($tmnpidinfo['versionhandling'] <= 2) {
          $mnpose = $tmnpidinfo['mnpose'];
          $mnlist = $tmnpidinfo['mnlist'];
          $mncurrentip = $tmnpidinfo['mncurrent'];
          $mncurrentlist[$uname] = $mncurrentip.":".$terracoindinfo['testnet'];
          foreach($tmnpidinfo['mnlastseen'] as $mnlsip => $data) {
            $mnlastseen[$uname][$mnlsip.':'.$terracoindinfo['testnet']] = $data;
          }
          foreach($tmnpidinfo['mnactiveseconds'] as $mnlsip => $data) {
            $mnactivesince[$uname][$mnlsip.':'.$terracoindinfo['testnet']] = $data;
          }
          $mndonationlist = $tmnpidinfo['mndonation'];
          $mnvoteslist = $tmnpidinfo['mnvotes'];
          $mnpubkeylist = $tmnpidinfo['mnpubkey'];
          foreach($mnlist as $ip => $activetrue) {
            if ($activetrue != 1) {
              if (($activetrue == "ENABLED") || ($activetrue == "PRE_ENABLED") || ($activetrue == "WATCHDOG_EXPIRED")) {
                $active = 1;
              }
              else {
                $active = 0;
              }
              if (!in_array($activetrue,$mnstatusexvalues,true)) {
                echo "\nWARNING: ".$ip." - Unknown StatusEx: [".$activetrue."]\n";
                $activetrue = "__UNKNOWN__";
              }
            }
            else {
              $active = $activetrue;
            }
            $mnlistfinal["$ip:".$terracoindinfo['testnet']][$uname] = array('Status' => $active,
                                                                           'PoS' => $mnpose[$ip],
                                                                           'StatusEx' => $activetrue);
          }
          if (is_array($mnvoteslist) && (count($mnvoteslist)>0)) {
            foreach($mnvoteslist as $ip => $vote) {
              $mnvoteslistfinal["$ip:".$terracoindinfo['testnet']][$uname] = $vote;
            }
          }
          foreach($mnpubkeylist as $data) {
            $mnpubkeylistfinal[$data["ip"].":".$data["port"].":".$terracoindinfo['testnet'].":".$data["pubkey"]] = array(
                     "MasternodeIP" => $data["ip"],
                     "MasternodePort" => $data["port"],
                     "MNTestNet" => $terracoindinfo['testnet'],
                     "MNPubKey" => $data["pubkey"]
                );
          }
          if (is_array($mndonationlist)) {
            foreach($mndonationlist as $donatedata) {
              $mndonationlistfinal[$donatedata["ip"].":".$donatedata["port"].":".$terracoindinfo['testnet'].":".$donatedata["pubkey"]] = array(
                     "MasternodeIP" => $donatedata["ip"],
                     "MasternodePort" => $donatedata["port"],
                     "MNTestNet" => $terracoindinfo['testnet'],
                     "MNPubKey" => $donatedata["pubkey"],
                     "MNDonationPercentage" => $donatedata["percent"]
                );
            }
          }
        }
        // New version handling (3+) [v12+]
        elseif ($tmnpidinfo['versionhandling'] >= 3) {

          // Old budget handling (3) [v12.0]
          if ($tmnpidinfo['versionhandling'] == 3) {
              // Parse masternode budgets proposals
              if (is_array($tmnpidinfo['mnbudgetshow'])) {
                  foreach ($tmnpidinfo['mnbudgetshow'] as $mnbudgetid => $mnbudgetdata) {
                      if (array_key_exists($terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"], $mnbudgetshow)) {
                          if (($mnbudgetshow[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]]["Yeas"]
                                  + $mnbudgetshow[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]]["Nays"]
                                  + $mnbudgetshow[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]]["Abstains"]) < ($mnbudgetdata["Yeas"] + $mnbudgetdata["Nays"] + $mnbudgetdata["Abstains"])
                          ) {
                              $mnbudgetshow[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]] = $mnbudgetdata;
                              $mnbudgetshow[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]]['BudgetId'] = $mnbudgetid;
                              $mnbudgetshow[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]]["BudgetTesnet"] = $terracoindinfo['testnet'];
                          }
                      } else {
                          $mnbudgetshow[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]] = $mnbudgetdata;
                          $mnbudgetshow[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]]['BudgetId'] = $mnbudgetid;
                          $mnbudgetshow[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]]["BudgetTesnet"] = $terracoindinfo['testnet'];
                      }
                      if (array_key_exists("mnbudget-getvotes-" . $mnbudgetid, $tmnpidinfo)) {
                          if (!array_key_exists($mnbudgetid, $mnbudgetvotes[$terracoindinfo['testnet']])) {
                              $mnbudgetvotes[$terracoindinfo['testnet']][$mnbudgetid] = array();
                          }
                          if (is_array($tmnpidinfo["mnbudget-getvotes-" . $mnbudgetid])) {
                              foreach ($tmnpidinfo["mnbudget-getvotes-" . $mnbudgetid] as $mnbudgetvotehash => $mnbudgetvotedata) {
                                  if (array_key_exists($mnbudgetvotehash, $mnbudgetvotes[$terracoindinfo['testnet']][$mnbudgetid])) {
                                      if ($mnbudgetvotes[$terracoindinfo['testnet']][$mnbudgetid][$mnbudgetvotehash]["nTime"] < $mnbudgetvotedata["nTime"]) {
                                          $mnbudgetvotes[$terracoindinfo['testnet']][$mnbudgetid][$mnbudgetvotehash] = $mnbudgetvotedata;
                                      }
                                  } else {
                                      $mnbudgetvotes[$terracoindinfo['testnet']][$mnbudgetid][$mnbudgetvotehash] = $mnbudgetvotedata;
                                  }
                              }
                          }
                      }
                  }
              }

              // Parse masternode budgets projections
              if (is_array($tmnpidinfo['mnbudgetprojection'])) {
                  foreach ($tmnpidinfo['mnbudgetprojection'] as $mnbudgetid => $mnbudgetdata) {
                      if (is_array($mnbudgetdata) && array_key_exists("Yeas", $mnbudgetdata) && array_key_exists("Nays", $mnbudgetdata) && array_key_exists("Abstains", $mnbudgetdata)) {
                          if (array_key_exists($mnbudgetdata["Hash"], $mnbudgetprojection[$terracoindinfo['testnet']])) {
                              if (($mnbudgetprojection[$terracoindinfo['testnet']][$mnbudgetdata["Hash"]]["Yeas"]
                                      + $mnbudgetprojection[$terracoindinfo['testnet']][$mnbudgetdata["Hash"]]["Nays"]
                                      + $mnbudgetprojection[$terracoindinfo['testnet']][$mnbudgetdata["Hash"]]["Abstains"]) < ($mnbudgetdata["Yeas"] + $mnbudgetdata["Nays"] + $mnbudgetdata["Abstains"])
                              ) {
                                  $mnbudgetprojection[$terracoindinfo['testnet']][$mnbudgetdata["Hash"]] = $mnbudgetdata;
                                  $mnbudgetprojection[$terracoindinfo['testnet']][$mnbudgetdata["Hash"]]['BudgetId'] = $mnbudgetid;
                                  $mnbudgetprojection[$terracoindinfo['testnet']][$mnbudgetdata["Hash"]]["BudgetTesnet"] = $terracoindinfo['testnet'];
                              }
                          } else {
                              $mnbudgetprojection[$terracoindinfo['testnet']][$mnbudgetdata["Hash"]] = $mnbudgetdata;
                              $mnbudgetprojection[$terracoindinfo['testnet']][$mnbudgetdata["Hash"]]['BudgetId'] = $mnbudgetid;
                              $mnbudgetprojection[$terracoindinfo['testnet']][$mnbudgetdata["Hash"]]["BudgetTesnet"] = $terracoindinfo['testnet'];
                          }
                      }
                  }
              }

              // Parse masternode final budget
              if (is_array($tmnpidinfo['mnbudgetfinal'])) {
                  foreach ($tmnpidinfo['mnbudgetfinal'] as $mnbudgetid => $mnbudgetdata) {
                      if (array_key_exists($terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"], $mnbudgetfinal) &&
                          array_key_exists("VoteCount", $mnbudgetfinal[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]])
                      ) {
                          if (($mnbudgetfinal[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]]["VoteCount"]) < ($mnbudgetdata["VoteCount"])) {
                              $mnbudgetfinal[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]] = $mnbudgetdata;
                              $mnbudgetfinal[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]]['BudgetName'] = $mnbudgetid;
                              $mnbudgetfinal[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]]["BudgetTesnet"] = $terracoindinfo['testnet'];
                          }
                      } else {
                          $mnbudgetfinal[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]] = $mnbudgetdata;
                          $mnbudgetfinal[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]]['BudgetName'] = $mnbudgetid;
                          $mnbudgetfinal[$terracoindinfo['testnet'] . "-" . $mnbudgetdata["Hash"]]["BudgetTesnet"] = $terracoindinfo['testnet'];
                      }
                  }
              }
          }
          // gobject proposals and triggers handling (4) [v12.1]
          elseif ($tmnpidinfo['versionhandling'] == 4) {
              // Store the next superblock
              if (($governancenextsb[$terracoindinfo['testnet']] === false) || ($governancenextsb[$terracoindinfo['testnet']] > intval($tmnpidinfo['getgovernanceinfo']['nextsuperblock']))) {
                $governancenextsb[$terracoindinfo['testnet']] = intval($tmnpidinfo['getgovernanceinfo']['nextsuperblock']);
              }
              // Store the budget available in next superblock
              if (($governancebudget[$terracoindinfo['testnet']] === false) || ($governancebudget[$terracoindinfo['testnet']] > floatval($tmnpidinfo['getsuperblockbudget']))) {
                $governancebudget[$terracoindinfo['testnet']] = floatval($tmnpidinfo['getsuperblockbudget']);
              }
              // Parse proposals
              if (is_array($tmnpidinfo["gobjectlist"]) && is_array($tmnpidinfo["gobjectlist"]["proposals"])) {
                  foreach ($tmnpidinfo["gobjectlist"]["proposals"] as $proposaldata) {
                      if (array_key_exists($terracoindinfo['testnet'] . "-" . $proposaldata["hash"], $gobjectproposallist)) {
                          if (($gobjectproposallist[$terracoindinfo['testnet'] . "-" . $proposaldata["hash"]]["gobject"]["YesCount"]
                             + $gobjectproposallist[$terracoindinfo['testnet'] . "-" . $proposaldata["hash"]]["gobject"]["NoCount"]
                             + $gobjectproposallist[$terracoindinfo['testnet'] . "-" . $proposaldata["hash"]]["gobject"]["AbstainCount"]) < ($proposaldata["gobject"]["YesCount"] + $proposaldata["gobject"]["NoCount"] + $proposaldata["gobject"]["AbstainCount"])
                          ) {
                              $gobjectproposallist[$terracoindinfo['testnet'] . "-" . $proposaldata["hash"]] = $proposaldata;
                              $gobjectproposallist[$terracoindinfo['testnet'] . "-" . $proposaldata["hash"]]["Testnet"] = $terracoindinfo['testnet'];
                          }
                      } else {
                          $gobjectproposallist[$terracoindinfo['testnet'] . "-" . $proposaldata["hash"]] = $proposaldata;
                          $gobjectproposallist[$terracoindinfo['testnet'] . "-" . $proposaldata["hash"]]["Testnet"] = $terracoindinfo['testnet'];
                      }
                      if (array_key_exists("gobject-getvotes-" . $proposaldata["hash"], $tmnpidinfo)) {
                          if (!array_key_exists($proposaldata["hash"], $gobjectvotes[$terracoindinfo['testnet']])) {
                              $gobjectvotes[$terracoindinfo['testnet']][$proposaldata["hash"]] = array();
                          }
                          if (is_array($tmnpidinfo["gobject-getvotes-" . $proposaldata["hash"]])) {
                              foreach ($tmnpidinfo["gobject-getvotes-" . $proposaldata["hash"]] as $gobjectvotehash => $gobjectvotedata) {
                                list($collateral,$ntime,$vote,$signal) = explode(":",$gobjectvotedata);
                                if ($signal == "FUNDING") {
                                  if ((substr($collateral,0,16) == "CTxIn(COutPoint(") && (substr($collateral,-14) == "), scriptSig=)")) {
                                      $collateral = substr($collateral, 16, strlen($collateral) - 30);
                                      list($mnoutputhash, $mnoutputindex) = explode(", ", $collateral);
                                      if (array_key_exists($mnoutputhash."-".$mnoutputindex, $gobjectvotes[$terracoindinfo['testnet']][$proposaldata["hash"]])) {
                                          if ($gobjectvotes[$terracoindinfo['testnet']][$proposaldata["hash"]][$mnoutputhash."-".$mnoutputindex]["nTime"] < $ntime) {
                                              $gobjectvotes[$terracoindinfo['testnet']][$proposaldata["hash"]][$mnoutputhash."-".$mnoutputindex] = array("MasternodeOutputHash" => $mnoutputhash,
                                                  "MasternodeOutputIndex" => intval($mnoutputindex),
                                                  "VoteHash" => $gobjectvotehash,
                                                  "nTime" => intval($ntime),
                                                  "Vote" => $vote);
                                          }
                                      } else {
                                          $gobjectvotes[$terracoindinfo['testnet']][$proposaldata["hash"]][$mnoutputhash."-".$mnoutputindex] = array("MasternodeOutputHash" => $mnoutputhash,
                                              "MasternodeOutputIndex" => intval($mnoutputindex),
                                              "VoteHash" => $gobjectvotehash,
                                              "nTime" => intval($ntime),
                                              "Vote" => $vote);
                                      }

                                  }
                                }
                              }
                          }
                      }
                  }
              }
              if (is_array($tmnpidinfo["gobjectlist"]) && is_array($tmnpidinfo["gobjectlist"]["triggers"])) {
                  foreach ($tmnpidinfo["gobjectlist"]["triggers"] as $triggerdata) {
                      if (array_key_exists($terracoindinfo['testnet'] . "-" . $triggerdata["hash"], $gobjecttriggerlist)) {
                          if (($gobjecttriggerlist[$terracoindinfo['testnet'] . "-" . $triggerdata["hash"]]["gobject"]["YesCount"]
                                  + $gobjecttriggerlist[$terracoindinfo['testnet'] . "-" . $triggerdata["hash"]]["gobject"]["NoCount"]
                                  + $gobjecttriggerlist[$terracoindinfo['testnet'] . "-" . $triggerdata["hash"]]["gobject"]["AbstainCount"]) < ($triggerdata["gobject"]["YesCount"] + $triggerdata["gobject"]["NoCount"] + $triggerdata["gobject"]["AbstainCount"])
                          ) {
                              $gobjecttriggerlist[$terracoindinfo['testnet'] . "-" . $triggerdata["hash"]] = $triggerdata;
                              $gobjecttriggerlist[$terracoindinfo['testnet'] . "-" . $triggerdata["hash"]]["Testnet"] = $terracoindinfo['testnet'];

                          }
                      } else {
                          $gobjecttriggerlist[$terracoindinfo['testnet'] . "-" . $triggerdata["hash"]] = $triggerdata;
                          $gobjecttriggerlist[$terracoindinfo['testnet'] . "-" . $triggerdata["hash"]]["Testnet"] = $terracoindinfo['testnet'];
                      }
                      if (array_key_exists("gobject-getvotes-" . $triggerdata["hash"], $tmnpidinfo)) {
                          if (!array_key_exists($triggerdata["hash"], $gobjectvotes[$terracoindinfo['testnet']])) {
                              $gobjectvotes[$terracoindinfo['testnet']][$triggerdata["hash"]] = array();
                          }
                          if (is_array($tmnpidinfo["gobject-getvotes-" . $triggerdata["hash"]])) {
                              foreach ($tmnpidinfo["gobject-getvotes-" . $triggerdata["hash"]] as $gobjectvotehash => $gobjectvotedata) {
                                  list($collateral,$ntime,$vote,$signal) = explode(":",$gobjectvotedata);
                                  if ($signal == "FUNDING") {
                                      if ((substr($collateral,0,16) == "CTxIn(COutPoint(") && (substr($collateral,-14) == "), scriptSig=)")) {
                                          $collateral = substr($collateral, 16, strlen($collateral) - 30);
                                          list($mnoutputhash, $mnoutputindex) = explode(", ", $collateral);
                                          if (array_key_exists($mnoutputhash."-".$mnoutputindex, $gobjectvotes[$terracoindinfo['testnet']][$triggerdata["hash"]])) {
                                              if ($gobjectvotes[$terracoindinfo['testnet']][$triggerdata["hash"]][$mnoutputhash."-".$mnoutputindex]["nTime"] < $ntime) {
                                                  $gobjectvotes[$terracoindinfo['testnet']][$triggerdata["hash"]][$mnoutputhash."-".$mnoutputindex] = array("MasternodeOutputHash" => $mnoutputhash,
                                                      "MasternodeOutputIndex" => intval($mnoutputindex),
                                                      "VoteHash" => $gobjectvotehash,
                                                      "nTime" => intval($ntime),
                                                      "Vote" => $vote);
                                              }
                                          } else {
                                              $gobjectvotes[$terracoindinfo['testnet']][$triggerdata["hash"]][$mnoutputhash."-".$mnoutputindex] = array("MasternodeOutputHash" => $mnoutputhash,
                                                  "MasternodeOutputIndex" => intval($mnoutputindex),
                                                  "VoteHash" => $gobjectvotehash,
                                                  "nTime" => intval($ntime),
                                                  "Vote" => $vote);
                                          }

                                      }
                                  }
                              }
                          }
                      }
                  }
              }

          }

          // Parse the masternode list
          $mn3listfull = $tmnpidinfo['mnlistfull'];
          foreach($mn3listfull as $mn3output => $mn3data) {
              // Remove all extra spaces
            $mn3data = trim($mn3data);
            do {
              $rcount = 0;
              $mn3data = str_replace("  "," ",$mn3data, $rcount);
            } while ($rcount > 0);

            // Store each value separated by spaces
            if ($tmnpidinfo['versionhandling'] == 3) {
              list($mn3status, $mn3protocol, $mn3pubkey, $mn3ipport, $mn3lastseen, $mn3activeseconds, $mn3lastpaid) = explode(" ",$mn3data);
            }
            else {
              list($mn3status, $mn3protocol, $mn3pubkey, $mn3lastseen, $mn3activeseconds, $mn3lastpaid, $mn4lastpaidblock, $mn3ipport) = explode(" ",$mn3data);
            }

            // Handle the IPs
            if (substr($mn3ipport,0,1) == "[") {
              // IPv6
              list($mn3ip, $mn3port) = explode("]:", substr($mn3ipport,1,strlen($mn3ipport)-1));
            }
            else {
              // IPv4
              $test = explode(":", $mn3ipport);
              if (!array_key_exists(1,$test)) {
                var_dump($mn3ipport);
              }
              list($mn3ip, $mn3port) = $test;
            }

            if (array_key_exists($mn3output."-".$terracoindinfo['testnet'],$mninfo2)) {
              if ($mn3lastseen < $mninfo2[$mn3output."-".$terracoindinfo['testnet']]["MasternodeLastSeen"]) {
                $mninfo2[$mn3output."-".$terracoindinfo['testnet']]["MasternodeLastSeen"] = intval($mn3lastseen);
              }
              if ($mn3activeseconds < $mninfo2[$mn3output."-".$terracoindinfo['testnet']]["MasternodeActiveSeconds"]) {
                $mninfo2[$mn3output."-".$terracoindinfo['testnet']]["MasternodeActiveSeconds"] = intval($mn3activeseconds);
              }
              if ($mn3lastpaid > $mninfo2[$mn3output."-".$terracoindinfo['testnet']]["MasternodeLastPaid"]) {
                $mninfo2[$mn3output."-".$terracoindinfo['testnet']]["MasternodeLastPaid"] = intval($mn3lastpaid);
              }
            }
            else {
              $mninfo2[$mn3output."-".$terracoindinfo['testnet']] = array("MasternodeProtocol" => intval($mn3protocol),
                                                                         "MasternodePubkey" => $mn3pubkey,
                                                                         "MasternodeIP" => $mn3ip,
                                                                         "MasternodePort" => $mn3port,
                                                                         "MasternodeLastSeen" => intval($mn3lastseen),
                                                                         "MasternodeActiveSeconds" => intval($mn3activeseconds),
                                                                         "MasternodeLastPaid" => $mn3lastpaid);
            }
            if (($mn3status == "ENABLED") || ($mn3status == "PRE_ENABLED")) {
              $active = 1;
            }
            else {
              $active = 0;
            }
            if (!in_array($mn3status,$mnstatusexvalues,true)) {
              echo "\nWARNING: ".$mn3output." - Unknown StatusEx: [".$mn3status."] ";
              $mn3status = "__UNKNOWN__";
            }
            $mnlist2final[$mn3output."-".$terracoindinfo['testnet']][$uname] = array('Status' => $active,
                                                                                    'StatusEx' => $mn3status);
          }
        }
        echo " $tmnip\n";
      }
      elseif ($tmnenabled) {
        $iponly = $tmnpidinfo['conf']->getconfig('bind');
        $ip = "$iponly:$port";
        $country = tmn_getcountry($ip,$countrycode);
        if ($country === false) {
          $country = 'Unknown';
          $countrycode = '__';
        }
        $processstatus = 'notresponding';
        $tmnpidtorestart[$tmnnum] = $tmnpidinfo;
        echo "NR ".str_repeat(" ",96)."$ip\n";
      }
      else {
        $processstatus = 'disabled';
        echo "--\n";
      }
    }
    elseif ($tmnenabled) {
      // Remove the notresponding counter file
      if (file_exists(TMN_NRCOUNTDIR."tmnctl-NR-$uname-counter")) {
        unlink(TMN_NRCOUNTDIR."tmnctl-NR-$uname-counter");
      }
      $iponly = $tmnpidinfo['conf']->getconfig('bind');
      $ip = "$iponly:$port";
      $country = tmn_getcountry($ip,$countrycode);
      if ($country === false) {
        $country = 'Unknown';
        $countrycode = '__';
      }
      $processstatus = 'stopped';
      echo "NS ".str_repeat(" ",96)."$ip\n";
    }
    else {
      // Remove the notresponding counter file
      if (file_exists(TMN_NRCOUNTDIR."tmnctl-NR-$uname-counter")) {
        unlink(TMN_NRCOUNTDIR."tmnctl-NR-$uname-counter");
      }
      $processstatus = 'disabled';
      echo "--\n";
    }
    $wsstatus[$uname] = array("ProcessStatus" => $processstatus,
                              "Version" => $version,
                              "Protocol" => $protocol,
                              "Blocks" => $blocks,
                              "LastBlockHash" => $blockhash,
                              "Connections" => $connections,
                              "Country" => $country,
                              "CountryCode" => $countrycode,
                              "Spork" => $spork[$uname]);
  }
  xecho($separator);
  ksort($mnpubkeylistfinal,SORT_NATURAL);
  $mnlastseenfinal = array();
  foreach($mnlastseen as $uname => $mnlastseenlist) {
    foreach($mnlastseenlist as $ip => $lastseentimestamp) {
      if ((array_key_exists($ip,$mnlastseenfinal) && ($mnlastseenfinal[$ip] > $lastseentimestamp)) || !array_key_exists($ip,$mnlastseenfinal)) {
        $mnlastseenfinal[$ip] = $lastseentimestamp;
      }
    }
  }
  ksort($mnlastseenfinal,SORT_NATURAL);
  $mnactivesincefinal = array();
  foreach($mnactivesince as $uname => $mnactivesincelist) {
    foreach($mnactivesincelist as $ip => $activeseconds) {
      if ((array_key_exists($ip,$mnactivesincefinal) && ($mnactivesincefinal[$ip] < $activeseconds)) || !array_key_exists($ip,$mnactivesincefinal)) {
        $mnactivesincefinal[$ip] = $activeseconds;
      }
    }
  }
  ksort($mnactivesincefinal,SORT_NATURAL);
  $mncountinactive = 0;
  $mncountactive = 0;
  foreach($mnlistfinal as $ip => $info) {
    $inactiveresult = true;
    foreach($info as $uname => $mnactive) {
      $inactiveresult = $inactiveresult && (($mnactive == 0) || ($mnactive === false));
    }
    if ($inactiveresult ) {
      $mncountinactive++;
    }
    else {
      $mncountactive++;
    }
  }
  $mninfodel = array();
  foreach($mninfolast as $ip) {
    if (!array_key_exists($ip,$mnlistfinal)) {
      $info = explode(":",$ip);
      $mninfodel[] = array('ip' => $info[0], 'port' => $info[1]);
    }
  }
  $mncount = $mncountinactive+$mncountactive;
  if (count($mnlistfinal) > 0) {
    ksort($mnlistfinal,SORT_NATURAL);
    $estpayoutdaily = round(tmn_getpayout($mncountactive,$terracoindinfo['blocks']),2);
  }
  else {
    $estpayoutdaily = '???';
  }

//  echo "Total Masternodes: $mncount/$mncountinactive    Est.Payout: $estpayoutdaily TRC/day (diff=$difficultyfinal)\n";

  if (count($wsstatus)>0) {
    $wsmninfo = array();
    $wsmnlist = array();
    foreach($mnlistfinal as $ip => $mninfo) {
      $ipport = explode(":",$ip);
      $mnip = $ipport[0];
      $mnport = $ipport[1];
      $mntestnet = $ipport[2];
      if (array_key_exists($ip,$mnactivesincefinal)) {
        $mnactiveseconds = $mnactivesincefinal[$ip];
      }
      else {
        $mnactiveseconds = 0;
      }
      if (array_key_exists($ip,$mnlastseenfinal)) {
        $mnlastseen = $mnlastseenfinal[$ip];
      }
      else {
        $mnlastseen = 0;
      }
      $mncountry = tmn_getcountry($ip,$mncountrycode);
      if ($mncountry === false) {
        $mncountry = 'Unknown';
        $mncountrycode = '__';
      }
      $wsmninfo[] = array("MasternodeIP" => $mnip,
                          "MasternodePort" => $mnport,
                          "MNTestNet" => $mntestnet,
                          "MNActiveSeconds" => $mnactiveseconds,
                          "MNLastSeen" => $mnlastseen,
                          "MNCountry" => $mncountry,
                          "MNCountryCode" => $mncountrycode);

      foreach($mninfo as $mnuname => $mnactive) {
        if ($mnactive['Status'] == 1) {
          if (array_key_exists($mnuname,$mncurrentlist) && ($ip == $mncurrentlist[$uname])) {
            $mnstatus = 'current';
          }
          else {
            $mnstatus = 'active';
          }
        }
        elseif ($mnactive['Status'] === false) {
          $mnstatus = 'unlisted';
        }
        else {
          $mnstatus = 'inactive';
        }
        $wsmnlist[] = array("MasternodeIP" => $mnip,
                            "MasternodePort" => $mnport,
                            "MNTestNet" => $mntestnet,
                            "FromNodeUName" => $mnuname,
                            "MasternodeStatus" => $mnstatus,
                            "MasternodeStatusPoS" => $mnactive['PoS'],
                            "MasternodeStatusEx" => $mnactive['StatusEx']);
      }
    }
    $wsmnpubkeys = array();
    foreach ($mnpubkeylistfinal as $key => $data) {
      $wsmnpubkeys[] = $data;
    }
    $wsmndonation = array();
    foreach ($mndonationlistfinal as $key => $data) {
      $wsmndonation[] = $data;
    }
    $wsmnvotes = array();
    foreach($mnvoteslistfinal as $ip => $mnvotesinfo) {
      $ipport = explode(":",$ip);
      $mnip = $ipport[0];
      $mnport = $ipport[1];
      $mntestnet = $ipport[2];
      foreach($mnvotesinfo as $mnuname => $mnvote) {
        $wsmnvotes[] = array("MasternodeIP" => $mnip,
                             "MasternodePort" => $mnport,
                             "MNTestNet" => $mntestnet,
                             "FromNodeUName" => $mnuname,
                             "MasternodeVote" => $mnvote);
      }
    }

    // v12 handling / VersionHandling = 3
    $wsmninfo2 = array();
    foreach($mninfo2 as $output => $mninfo) {
      list($mnoutputhash, $mnoutputindex, $mntestnet) = explode("-", $output);
      $wsmninfo2[] = array("MasternodeOutputHash" => $mnoutputhash,
                           "MasternodeOutputIndex" => $mnoutputindex,
                           "MasternodeTestNet" => $mntestnet,
                           "MasternodeProtocol" => $mninfo["MasternodeProtocol"],
                           "MasternodePubkey" => $mninfo["MasternodePubkey"],
                           "MasternodeIP" => $mninfo["MasternodeIP"],
                           "MasternodePort" => $mninfo["MasternodePort"],
                           "MasternodeLastSeen" => $mninfo["MasternodeLastSeen"],
                           "MasternodeActiveSeconds" => $mninfo["MasternodeActiveSeconds"],
                           "MasternodeLastPaid" => $mninfo["MasternodeLastPaid"]);
    }

    $wsmnlist2 = array();
    foreach($mnlist2final as $output => $mninfo) {
      list($mnoutputhash, $mnoutputindex, $mntestnet) = explode("-", $output);
      foreach($mninfo as $mnuname => $mnactive) {
        if ($mnactive['Status'] == 1) {
          $mnstatus = 'active';
        }
        elseif ($mnactive['Status'] === false) {
          $mnstatus = 'unlisted';
        }
        else {
          $mnstatus = 'inactive';
        }
        $wsmnlist2[] = array("MasternodeOutputHash" => $mnoutputhash,
                             "MasternodeOutputIndex" => $mnoutputindex,
                             "MasternodeTestNet" => $mntestnet,
                             "FromNodeUName" => $mnuname,
                             "MasternodeStatus" => $mnstatus,
                             "MasternodeStatusEx" => $mnactive['StatusEx']);
      }
    }

    $wsmnbudgetshow = array();
    foreach($mnbudgetshow as $budgetinfo) {
      $wsmnbudgetshow[] = $budgetinfo;
    }

    $wsmnbudgetvotes = array();
    foreach($mnbudgetvotes as $testnet => $mnbudgetvotesdata) {
      foreach($mnbudgetvotesdata as $budgetid => $mnbudgetvotesdata2) {
        foreach($mnbudgetvotesdata2 as $mnvotehash => $mnbudgetvotesdata3) {
          list($mnoutputhash, $mnoutputindex) = explode("-", $mnvotehash);
          $wsmnbudgetvotes[] = array(
              'BudgetTestnet' => intval($testnet),
              'BudgetId' => $budgetid,
              'MasternodeOutputHash' => $mnoutputhash,
              'MasternodeOutputIndex' => intval($mnoutputindex),
              'VoteHash' => $mnbudgetvotesdata3["nHash"],
              'VoteValue' => $mnbudgetvotesdata3["Vote"],
              'VoteTime' => $mnbudgetvotesdata3["nTime"],
              'VoteIsValid' => $mnbudgetvotesdata3["fValid"],
          );
        }
      }
    }

    $wsmnbudgetprojection = array();
    foreach($mnbudgetprojection as $mnbudgetdata) {
      foreach($mnbudgetdata as $budgetinfo) {
        $wsmnbudgetprojection[] = $budgetinfo;
      }
    }

    $wsmnbudgetfinal = array();
    foreach($mnbudgetfinal as $budgetinfo) {
        $wsmnbudgetfinal[] = $budgetinfo;
    }

    $wsgoproposals = array();
    foreach($gobjectproposallist as $proposalinfo) {
      unset($proposalinfo["gobject"]["Hash"]);
      $wsgoproposals[] = $proposalinfo;
    }

    $wsgotriggers = array();
    foreach($gobjecttriggerlist as $triggerinfo) {
      $wsgotriggers[] = $triggerinfo;
    }

    $wsgobjectvotes = array();
    foreach($gobjectvotes as $testnet => $gobjectvotesdata) {
       foreach($gobjectvotesdata as $gobjecthash => $gobjectvotedata2) {
              foreach($gobjectvotedata2 as $gobjectvotedata3) {
                  $wsgobjectvotes[] = array(
                      'GovernanceObjectTestnet' => intval($testnet),
                      'GovernanceObjectId' => $gobjecthash,
                      'MasternodeOutputHash' => $gobjectvotedata3["MasternodeOutputHash"],
                      'MasternodeOutputIndex' => $gobjectvotedata3["MasternodeOutputIndex"],
                      'VoteHash' => $gobjectvotedata3["VoteHash"],
                      'VoteValue' => $gobjectvotedata3["Vote"],
                      'VoteTime' => $gobjectvotedata3["nTime"],
                  );
              }
       }
    }

    xecho("Submitting status via webservice (".count($wsstatus)." entries): ");
    $response = '';
    $payload = array('nodes' => $wsstatus,
                     'testnet' => $istestnet,
                     'mninfo' => $wsmninfo,
                     'mninfo2' => $wsmninfo2,
                     'mnpubkeys' => $wsmnpubkeys,
                     'mndonation' => $wsmndonation,
                     'mnlist' => $wsmnlist,
                     'mnlist2' => $wsmnlist2,
                     'mnvotes' => $wsmnvotes,
                     // v0.12.0 budgets
                     'mnbudgetshow' => $wsmnbudgetshow,
                     'mnbudgetfinal' => $wsmnbudgetfinal,
                     'mnbudgetvotes' => $wsmnbudgetvotes,
                     'mnbudgetprojection' => $wsmnbudgetprojection,
                     // v0.12.1 budgets
                     'gobjproposals' => $wsgoproposals,
                     'gobjtriggers' => $wsgotriggers,
                     'gobjvotes' => $wsgobjectvotes,
                     'stats' => array('networkhashps' => $networkhashps,
                                      'governancenextsuperblock' => $governancenextsb[$istestnet],
                                      'governancebudget' =>  $governancebudget[$istestnet]));
    $contentraw = tmn_cmd_post('ping',$payload,$response);
    if (strlen($contentraw) > 0) {
      $content = json_decode($contentraw,true);
      if (($response['http_code'] >= 200) && ($response['http_code'] <= 299)) {
        echo "Success (".$response['http_code'].")\n";
        if (is_array($content["data"])) {
          xecho("+ Nodes: ");
          if ($content["data"]["nodes"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["nodes"]."\n";
          }
          xecho("+ Masternodes Info (<=v0.11): ");
          if ($content["data"]["mninfo"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["mninfo"]."\n";
          }
          xecho("+ Masternodes Info (>=v0.12): ");
          if ($content["data"]["mninfo2"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["mninfo2"]."\n";
          }
          xecho("+ Masternodes Pubkeys (<=v0.11): ");
          if ($content["data"]["mnpubkeys"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["mnpubkeys"]."\n";
          }
          xecho("+ Masternodes Donations (<=v0.11): ");
          if ($content["data"]["mndonation"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["mndonation"]."\n";
          }
          xecho("+ Masternodes List (<=v0.11): ");
          if ($content["data"]["mnlist"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["mnlist"]."\n";
          }
          xecho("+ Masternodes List (>=v0.12): ");
          if ($content["data"]["mnlist2"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["mnlist2"]."\n";
          }
          xecho("+ Masternodes Portcheck: ");
          if ($content["data"]["portcheck"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["portcheck"]."\n";
          }
          xecho("+ Masternodes Votes: ");
          if ($content["data"]["mnvotes"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["mnvotes"]."\n";
          }
          xecho("+ Spork: ");
          if ($content["data"]["spork"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["spork"]."\n";
          }
          xecho("+ Stats (Mainnet): ");
          if ($content["data"]["stats"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["stats"]."\n";
          }
          xecho("+ Stats (Testnet): ");
          if ($content["data"]["stats2"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["stats2"]."\n";
          }
          xecho("+ Budget (Show): ");
          if ($content["data"]["mnbudgetshow"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["mnbudgetshow"]."\n";
          }
          xecho("+ Budget (Projection): ");
          if ($content["data"]["mnbudgetprojection"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["mnbudgetprojection"]."\n";
          }
          xecho("+ Budget (Votes): ");
          if ($content["data"]["mnbudgetvotes"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["mnbudgetvotes"]."\n";
          }
          xecho("+ Final Budget: ");
          if ($content["data"]["mnbudgetfinal"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["mnbudgetfinal"]."\n";
          }
          xecho("+ Governance Object Proposals: ");
          if ($content["data"]["gobjproposals"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["gobjproposals"]."\n";
          }
          xecho("+ Governance Object Triggers: ");
          if ($content["data"]["gobjtriggers"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["gobjtriggers"]."\n";
          }
          xecho("+ Governance Object Triggers Payments: ");
          if ($content["data"]["gobjtriggerspayments"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["gobjtriggerspayments"]."\n";
          }
          xecho("+ Governance Object Triggers Payments Trim: ");
          if ($content["data"]["gobjtriggerspaymentstrim"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["gobjtriggerspaymentstrim"]."\n";
          }
          xecho("+ Governance Object Votes: ");
          if ($content["data"]["gobjvotes"] === false) {
            echo "Failed!\n";
          } else {
            echo $content["data"]["gobjvotes"]."\n";
          }
        }
      }
      elseif (($response['http_code'] >= 400) && ($response['http_code'] <= 499)) {
        echo "Error (".$response['http_code'].": ".$content['message'].")\n";
      }
      elseif (($response['http_code'] >= 500) && ($response['http_code'] <= 599)) {
          echo "Unknown Error (".$response['http_code'].")\n";
          var_dump($response['http_code']);
          var_dump($content);
          var_dump($contentraw);
      }
      else {
        echo "Unknown (".$response['http_code'].")\n";
      }
    }
    else {
      echo "Error (empty result) [HTTP CODE ".$response['http_code']."]\n";
    }
  }

  if (count($tmnpidtorestart)>0) {
    tmn_restartfrozen($tmnpidtorestart);
  }

}

//#############################################################################
//#############################################################################
//
//                               MAIN PROGRAM
//
//#############################################################################
//#############################################################################

$lastrefresh = gmdate('Y-m-d H:i:s');
$starttime = microtime(true);

xecho("TRC Ninja Control [tmnctl] v".TMN_VERSION." (".date('Y-m-d H:i:s',filemtime(__FILE__)).")\n");

if ($argc > 1) {
  $istestnet = 0;
  if ($argc > 2) {
      if ( ( (strcasecmp($argv[1], 'status') == 0)
              || (strcasecmp($argv[1],'start') == 0)
              || (strcasecmp($argv[1],'stop') == 0)
              || (strcasecmp($argv[1],'restart') == 0) )
           && ((strcasecmp($argv[2], 'testnet') == 0))) {
          $istestnet = 1;
      }
  }
  xecho("Querying list of nodes for this hub: ");
  $params = array();
  $content = tmn_cmd_get('nodes',$params,$response);
  $nodes = array();
  if (strlen($content) > 0) {
    $content = json_decode($content,true);
    if (($response['http_code'] >= 200) && ($response['http_code'] <= 299)) {
      $nodes = $content['data'];
      echo "Success (".count($nodes)." nodes)\n";
    }
    elseif (($response['http_code'] >= 400) && ($response['http_code'] <= 499)) {
      if (!empty($content['message']))
        echo "Error (".$response['http_code'].": ".$content['message'].")\n";
      else if (!empty($content['message']))
        foreach($content['message'] as $msg)
          echo "Error (".$response['http_code'].": ".$msg.")\n";
      else
        echo "Error (".$response['http_code'].": Unknown)\n";
    }
  }
  else {
    echo "Error (empty result) [HTTP CODE ".$response['http_code']."]\n";
  }
  unset($content,$response,$params);

  $tmnpidstatus = tmn_getpids($nodes,(strcasecmp($argv[1],'status') == 0),$istestnet);
  $tmnpid = $tmnpidstatus;
  tmn_startkeeprunning($tmnpid);

}

if ($argc == 1) {
  tmn_help($argv[0]);
}
elseif ((strcasecmp($argv[1],'address') == 0) && ($argc == 4)) {
  tmn_address($tmnpid,$argv[2],$argv[3]);
}
elseif (strcasecmp($argv[1],'disable') == 0) {
  $tmntodisable = array();
  for ($x = 2; $x < $argc; $x++) {
    $tmntodisable[] = $argv[$x];
  }
  tmn_disable($tmnpid,$tmntodisable);
}
elseif (strcasecmp($argv[1],'enable') == 0) {
  $tmntoenable = array();
  for ($x = 2; $x < $argc; $x++) {
    $tmntoenable[] = $argv[$x];
  }
  tmn_enable($tmnpid,$tmntoenable);
}
elseif (strcasecmp($argv[1],'status') == 0) {
  $semfnam = ($istestnet?TMN_CTLSTATUSAUTO_TEST_SEMAPHORE:TMN_CTLSTATUSAUTO_MAIN_SEMAPHORE);
  file_put_contents($semfnam,sprintf('%s',getmypid()));
  tmn_status($tmnpidstatus,$istestnet);
  unlink($semfnam);
}
elseif ((strcasecmp($argv[1],'start') == 0)
     || (strcasecmp($argv[1],'stop') == 0)
     || (strcasecmp($argv[1],'restart') == 0)) {
  $todo = strtolower($argv[1]);
  $testnet = ($argc > 2) && ($argv[2] == 'testnet');
  if (($argc > 3)
   && ((strcasecmp($argv[3],'p2pool') == 0)
    || (strcasecmp($argv[3],'masternode') == 0))) {
    $nodetype = $argv[3];
  }
  else {
    $nodetype = "masternode";
  }
  tmn_startstop($tmnpid,$todo,$testnet,$nodetype,($argc > 4) && (strcasecmp($argv[4],'reindex') == 0));
}
elseif (strcasecmp($argv[1],'version') == 0) {
  if ($argc == 6) {
    tmn_version_create($argv[2],$argv[3],$argv[4],$argv[5]);
  }
  else {
    tmn_help($argv[0]);
    echo "Not enough parameters for version action.\n";
  }
}
elseif (strcasecmp($argv[1],'create') == 0) {
  if ($argc == 3) {
    tmn_create($tmnpid,$argv[2]);
  }
  else if ($argc == 4) {
    tmn_create($tmnpid,$argv[2],$argv[3]);
  }
  else {
    tmn_help($argv[0]);
  }
}
else {
  tmn_help($argv[0]);
  echo "Unknown action: ".$argv[1]."\n";
}

?>
