<?php

declare(strict_types=1); // for sanity

namespace SCALENE; // for isolation


final class Scalene
{
  public static bool $cpu_only = false;
  public static bool $use_virt_time = false;
  public static float $cpu_sampling_rate = 0.01; // sec
  public static int $malloc_threshold = 100; // # of samples
  public static ?string $profile_target = NULL;
  public static ?array $profile_target_args = NULL;

  private static $alloc_signal_file = NULL;
  private static $memcpy_signal_file = NULL;
  private static int $alloc_signal_file_pos = 0;
  private static int $memcpy_signal_file_pos = 0;

  private static array $cpu_samples_php = array();
  private static array $cpu_samples_c = array();
  private static array $malloc_samples = array();
  private static array $malloc_samples_php = array();
  private static array $malloc_counters = array(); // per file counters
  private static array $free_samples = array();
  private static array $memcpy_samples = array();

  private static bool $in_signal_handler = false;
  private static float $last_signal_time_virt = 0.0;
  private static float $current_footprint = 0.0;
  private static float $max_footprint = 0.0;
  private static float $total_allocs = 0.0;
  private static float $total_free = 0.0;
  private static float $total_copy = 0.0;

  public static function signal_dispatch(int $signo)
  {
    // avoid self-recursion
    if (self::$in_signal_handler) {
      return;
    } else {
      self::$in_signal_handler = true;
    }

    switch ($signo) {
      case SIGALRM: // wall clock time
        // echo "SIGALRM received\n";
        self::cpu_signal_handler();
        break;
      case SIGVTALRM: // CPU time
        // echo "SIGVTALRM received\n";
        self::cpu_signal_handler();
        break;
      case SIGXCPU: // malloc
        // echo "SIGXCPU received\n";
        self::alloc_signal_handler();
        break;
      case SIGXFSZ: // free
        // echo "SIGXFSZ received\n";
        self::alloc_signal_handler();
        break;
      case SIGPROF: // memcpy
        // echo "SIGPROF received\n";
        self::memcpy_signal_handler();
        break;
      default:
        echo "signal $signo received\n";
        exit;
    }

    // reset
    self::$in_signal_handler = false;
  }

  private static function cpu_signal_handler()
  {
    // get elapsed times
    $elapsed_virt = self::get_process_time() - self::$last_signal_time_virt;

    // get stack trace
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    if (!self::should_trace($trace)) {
      return;
    }

    // save samples
    $c_time = $elapsed_virt - self::$cpu_sampling_rate;
    if ($c_time < 0.0) {
      $c_time = 0;
    }
    $entry = $trace[1]["file"] . ":" . strval($trace[1]["line"]);

    if (array_key_exists($entry, self::$cpu_samples_php)) {
      self::$cpu_samples_php[$entry] += self::$cpu_sampling_rate;
      self::$cpu_samples_c[$entry] += $c_time;
    } else {
      self::$cpu_samples_php[$entry] = self::$cpu_sampling_rate;
      self::$cpu_samples_c[$entry] = $c_time;
    }

    // avoid time skew
    self::update_timestamps();
  }

  private static function alloc_signal_handler()
  {
    // get stack trace
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    if (!self::should_trace($trace)) {
      return;
    }

    // read data from the runtime
    if (fseek(self::$alloc_signal_file, self::$alloc_signal_file_pos)) {
      echo "fseek() failed!\n";
      exit;
    }

    $data = array();
    while (($line = fgets(self::$alloc_signal_file)) != false) {
      if ($line == "\n") {
        break; // end marker reached
      }

      // each element = [action, timestamp, size, php_fraction]
      $data[] = explode(",", $line);
      self::$alloc_signal_file_pos += (strlen($line));
    }

    // calculate & record stats
    if (sort($data) == false) {
      echo "failed to sort data!\n";
      exit;
    }

    $allocs = 0.0;
    $php_allocs = 0.0;
    $before = self::$current_footprint;

    foreach ($data as $entry) {
      $is_malloc = ($entry[0] == "M");
      // $timestamp = intval($entry[1]);
      $size = floatval($entry[2]) / (1024 * 1024);
      $php_fraction = floatval($entry[3]);

      if ($is_malloc) {
        self::$current_footprint += $size;
        $allocs += $size;
        $php_allocs += $php_fraction * $size;

        if (self::$current_footprint > self::$max_footprint) {
          self::$max_footprint = self::$current_footprint;
        }
      } else {
        self::$current_footprint -= $size;
      }
    }

    $net = self::$current_footprint - $before;
    self::$total_allocs += $allocs;
    self::$total_free += ($allocs - $net);

    // save samples
    $entry = $trace[1]["file"] . ":" . strval($trace[1]["line"]);
    if ($net > 0)
    {
      if (array_key_exists($entry, self::$malloc_samples)) {
        self::$malloc_samples[$entry] += $net;
        self::$malloc_samples_php[$entry] += ($php_allocs / $allocs) * $net;
      } else {
        self::$malloc_samples[$entry] = $net;
        self::$malloc_samples_php[$entry] = ($php_allocs / $allocs) * $net;
      }

      if (array_key_exists($trace[1]["file"], self::$malloc_counters)) {
        self::$malloc_counters[$entry] += 1;
      } else {
        self::$malloc_counters[$entry] = 1;
      }
    }
    else
    {
      if (array_key_exists($entry, self::$free_samples)) {
        self::$free_samples[$entry] -= $net;
      } else {
        self::$free_samples[$entry] = -$net;
      }
    }
  }

  private static function memcpy_signal_handler()
  {
    // get stack trace
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    if (!self::should_trace($trace)) {
      return;
    }

    // read data from the runtime
    if (fseek(self::$memcpy_signal_file, self::$memcpy_signal_file_pos)) {
      echo "fseek() failed!\n";
      exit;
    }

    $data = array();
    while (($line = fgets(self::$memcpy_signal_file)) != false) {
      if ($line == "\n") {
        break; // end marker reached
      }

      // each element = [timestamp, size]
      $data[] = explode(",", $line);
      self::$memcpy_signal_file_pos += (strlen($line));
    }

    // calculate & record stats
    if (sort($data) == false) {
      echo "failed to sort data!\n";
      exit;
    }

    $copy = 0.0;
    foreach ($data as $entry) {
      // $timestamp = intval($entry[0]);
      $size = floatval($entry[1]) / (1024 * 1024);

      $copy += $size;
    }
    self::$total_copy += $copy;

    // save samples
    $entry = $trace[1]["file"] . ":" . strval($trace[1]["line"]);
    if (array_key_exists($entry, self::$memcpy_samples)) {
      self::$memcpy_samples[$entry] += $copy;
    } else {
      self::$memcpy_samples[$entry] = $copy;
    }
  }

  public static function open_signal_files()
  {
    $file_name = "/tmp/scalene-malloc-signal" . strval(posix_getpid());
    $handle = fopen($file_name, "r");
    if ($handle == false) {
      echo "fopen() failed for alloc signal file!\n";
      exit;
    } else {
      self::$alloc_signal_file = $handle;
    }

    $file_name = "/tmp/scalene-memcpy-signal" . strval(posix_getpid());
    $handle = fopen($file_name, "r");
    if ($handle == false) {
      echo "fopen() failed for memcpy signal file!\n";
      exit;
    } else {
      self::$memcpy_signal_file = $handle;
    }
  }

  public static function start()
  {
    // print profile at exit
    register_shutdown_function("SCALENE\Scalene::dump_profile");

    // https://wiki.php.net/rfc/async_signals
    pcntl_async_signals(true);

    // install signal handlers
    if (!pcntl_signal(SIGALRM, "SCALENE\Scalene::signal_dispatch")) {
      echo "pcntl_signal(SIGALRM) failed!\n";
      exit;
    }
    if (!pcntl_signal(SIGVTALRM, "SCALENE\Scalene::signal_dispatch")) {
      echo "pcntl_signal(SIGVTALRM) failed!\n";
      exit;
    }
    if (!pcntl_signal(SIGXCPU, "SCALENE\Scalene::signal_dispatch")) {
      echo "pcntl_signal(SIGXCPU) failed!\n";
      exit;
    }
    if (!pcntl_signal(SIGXFSZ, "SCALENE\Scalene::signal_dispatch")) {
      echo "pcntl_signal(SIGFXSZ) failed!\n";
      exit;
    }
    if (!pcntl_signal(SIGPROF, "SCALENE\Scalene::signal_dispatch")) {
      echo "pcntl_signal(SIGPROF) failed!\n";
      exit;
    }
  }

  public static function end()
  {
    // https://wiki.php.net/rfc/async_signals
    pcntl_async_signals(false);

    // disable signal handlers
    if (!pcntl_signal(SIGALRM, SIG_IGN)) {
      echo "pcntl_signal(SIGALRM) failed!\n";
      exit;
    }
    if (!pcntl_signal(SIGVTALRM, SIG_IGN)) {
      echo "pcntl_signal(SIGVTALRM) failed!\n";
      exit;
    }
    if (!pcntl_signal(SIGXCPU, SIG_IGN)) {
      echo "pcntl_signal(SIGXCPU) failed!\n";
      exit;
    }
    if (!pcntl_signal(SIGXFSZ, SIG_IGN)) {
      echo "pcntl_signal(SIGFXSZ) failed!\n";
      exit;
    }
    if (!pcntl_signal(SIGPROF, SIG_IGN)) {
      echo "pcntl_signal(SIGPROF) failed!\n";
      exit;
    }
  }

  public static function dump_profile()
  {
    echo "\n==========CPU samples==========\n";
    echo "FILE (LINE): PHP Time (sec) | C Time (sec)\n";
    foreach (array_keys(self::$cpu_samples_php) as $key) {
      $arr = explode(":", $key);
      $php_t = round(self::$cpu_samples_php[$key], 2);
      $c_t = round(self::$cpu_samples_c[$key], 2);
      echo "$arr[0] ($arr[1]): $php_t | $c_t\n";
    }

    if (self::$cpu_only) {
      echo "\nDONE.\n";
      return;
    }

    echo "\n==========malloc samples==========\n";
    echo "FILE (LINE): Total Allocated (MiB) | PHP Allocated (MiB)\n";
    foreach (array_keys(self::$malloc_samples) as $key) {
      $arr = explode(":", $key);
      $allocs = round(self::$malloc_samples[$key], 2);
      $php_allocs = round(self::$malloc_samples_php[$key], 2);
      echo "$arr[0] ($arr[1]): $allocs | $php_allocs\n";
    }

    echo "\n==========free samples==========\n";
    echo "FILE (LINE): Total Freed (MiB)\n";
    foreach (array_keys(self::$free_samples) as $key) {
      $arr = explode(":", $key);
      $freed = round(self::$free_samples[$key], 2);
      echo "$arr[0] ($arr[1]): $freed\n";
    }

    echo "\n==========memcpy samples==========\n";
    echo "FILE (LINE): Total Copied (MiB)\n";
    foreach (array_keys(self::$memcpy_samples) as $key) {
      $arr = explode(":", $key);
      $copied = round(self::$memcpy_samples[$key], 2);
      echo "$arr[0] ($arr[1]): $copied\n";
    }

    echo "\n==========stats==========\n";
    echo "latest memory footprint (MiB): ", round(self::$current_footprint, 2), PHP_EOL;
    echo "max memory footprint (MiB): ", round(self::$max_footprint, 2), PHP_EOL;
    echo "total allocated (MiB): ", round(self::$total_allocs, 2), PHP_EOL;
    echo "total freed (MiB): ", round(self::$total_free, 2), PHP_EOL;
    echo "total copied (MiB): ", round(self::$total_copy, 2), PHP_EOL;

    echo "\nDONE.\n";
  }

  private static function should_trace(array $trace): bool
  {
    // don't profile the profiler
    if (strpos($trace[1]["file"], "scalene.php") == false) {
      return true;
    } else {
      return false;
    }
  }

  private static function get_process_time(): float
  {
    $t = 0.0;
    if (pcntl_process_time($t) == -1) {
      echo "pcntl_process_time() failed!\n";
      exit;
    }
    return $t;
  }

  public static function update_timestamps()
  {
    self::$last_signal_time_virt = self::get_process_time();
  }
}

?>
