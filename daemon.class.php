<?php
declare(ticks = 1);

define("ERR_EMAIL", "asdof71@yandex.ru");

define("BASE_DIR", dirname(__FILE__));
define("PID_FILE", "/var/run/synergy-daemon/synergy-daemon.pid");

ini_set('error_log', BASE_DIR . '/error.log');
fclose(STDIN);
fclose(STDOUT);
fclose(STDERR);
$STDIN = fopen('/dev/null', 'r');
$STDOUT = fopen(BASE_DIR . '/application.log', 'ab');
$STDERR = fopen(BASE_DIR . '/daemon.log', 'ab');

class DaemonClass {
     public $maxProcesses = 1;
     protected $stop_daemon = false;
     protected $currentJobs = array();

     private $mail_headers = array();

     public function __construct() {
          echo "Daemon start" . PHP_EOL;

          $this->mail_headers = array(
              'From' => 'daemon@synergy.ru',
              'X-Mailer' => 'PHP/' . phpversion()
          );

          pcntl_signal(SIGTERM, array($this, "sigHandler"));
          pcntl_signal(SIGCHLD, array($this, "sigHandler"));
     }

     public function sigHandler($signo, $pid = null, $status = null) {
          switch($signo) {
               case SIGTERM:
                    $this->stop_daemon = true;
                    break;
               case SIGCHLD:
                    if(!$pid) {
                         $pid = pcntl_waitpid(-1, $status, WNOHANG);
                    }

                    if($pid['status'] == 255) {
                         echo "Process #" . $pid['pid'] . " stop with error. Abort." . PHP_EOL;

                         $this->stop_daemon = true;

                         exit(0);
                    } else {
                         while ($pid > 0) {
                              if ($pid && isset($this->currentJobs[$pid['pid']])) {
                                   echo "Process #" . $pid['pid'] . " exit" . PHP_EOL;

                                   unset($this->currentJobs[$pid['pid']]);
                              }
                              $pid = pcntl_waitpid(-1, $status, WNOHANG);
                         }
                    }

                    break;
               default:
          }
     }

     public function isDaemonActive($pid_file) {
          if(is_file($pid_file)) {
               $pid = file_get_contents($pid_file);
               if(posix_kill($pid, 0)) {
                    return true;
               } else {
                    if(!unlink($pid_file)) {
                         exit(-1);
                    }
               }
          }
          return false;
     }

     public function run() {
          echo "Running daemon controller" . PHP_EOL;

          if ($this->isDaemonActive(PID_FILE)) {
               echo 'Daemon already active' . PHP_EOL;
               exit(-1);
          } else {
               file_put_contents(PID_FILE, getmypid());
          }

          while (!$this->stop_daemon) {
               while(count($this->currentJobs) >= $this->maxProcesses) {
                    // echo "Maximum children allowed, waiting..." . PHP_EOL;
                    sleep(1);
               }

               $this->launchJob();
          }
     }

     protected function launchJob() {
          $pid = pcntl_fork();
          if ($pid == -1) {
               error_log('PHP Could not launch new job, exiting', 1, ERR_EMAIL, implode($this->mail_headers));
               exit(-1);
          } else if ($pid) {
               $this->currentJobs[$pid] = true;
          } else {
               echo "Process #" . getmypid() . PHP_EOL;

               if($this->synergyTask()) {
                    echo "Waiting process #" . getmypid() . " 60 minutes" . PHP_EOL;

                    sleep(3600);

                    exit(0);
               } else {
                    exit(-1);
               }
          }
          return true;
     }

     private function xor($string, $key, $decrypt = false) {
          $sLength = strlen($string);
          $xLength = strlen($key);

          for($i = 0; $i < $sLength; $i++) {
               $string[$i] = $string[$i] ^ $key[$i % $xLength];
          }

          return $string;
     }

     private function request($url, $method = "POST", $data) {
          echo "Try request..." . PHP_EOL;

          $ch = curl_init();

          curl_setopt($ch, CURLOPT_URL, $url);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

          if(is_array($data) && count($data) > 0) $data = http_build_query($data);

          if(strlen($data) > 0) {
               if($method == "POST") {
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
               } else {
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Length: ' . strlen($data)));
               }
          }

          $output = curl_exec($ch);

          curl_close ($ch);

          echo "Request complete." . PHP_EOL;

          if(!empty($output)) {
               echo "Response isn't empty." . PHP_EOL;

               return json_decode($output);
          } else {
               echo "Response is empty. Abort." . PHP_EOL;
          }

          return false;
     }

     private function synergyTask() {
          $request = $this->request("https://syn.su/testwork.php", "POST", array("method" => "get"));

          if($request && $request->response != null) {
               $message = $request->response->message;
               $key = $request->response->key;

               $request = $this->request("https://syn.su/testwork.php", "POST", array("method" => "UPDATE", "message" => base64_encode($this->xor($message, $key))));
               // $request = $this->request("https://syn.su/testwork.php", "POST", array("method" => "update", "message" => base64_encode($this->xor($message, $key))));

               if($request && $request->response != null) {
                    if($request->errorCode == null && $request->response == "Success") {
                         echo "Good job!" . PHP_EOL;

                         return true;
                    } else {
                         error_log("Something went wrong... Error code: " . $request->errorCode . ". Error message: '" . $request->errorMessage . "'", 1, ERR_EMAIL, implode($this->mail_headers));
                    }
               } else {
                    error_log("Something went wrong... Error code: " . $request->errorCode . ". Error message: '" . $request->errorMessage . "'", 1, ERR_EMAIL, implode($this->mail_headers));
               }
          } else {
               error_log("Something went wrong... Error code: " . $request->errorCode . ". Error message: '" . $request->errorMessage . "'", 1, ERR_EMAIL, implode($this->mail_headers));
          }

          return false;
     }
}
