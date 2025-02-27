<?php

namespace Clue\React\SQLite;

use Clue\React\SQLite\Io\BlockingDatabase;
use Clue\React\SQLite\Io\LazyDatabase;
use Clue\React\SQLite\Io\ProcessIoDatabase;
use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Stream\DuplexResourceStream;

class Factory
{
    /** @var LoopInterface */
    private $loop;

    /** @var string */
    private $bin;

    /** @var bool */
    private $useSocket;

    /**
     * The `Factory` is responsible for opening your [`DatabaseInterface`](#databaseinterface) instance.
     *
     * ```php
     * $factory = new Clue\React\SQLite\Factory();
     * ```
     *
     * This class takes an optional `LoopInterface|null $loop` parameter that can be used to
     * pass the event loop instance to use for this object. You can use a `null` value
     * here in order to use the [default loop](https://github.com/reactphp/event-loop#loop).
     * This value SHOULD NOT be given unless you're sure you want to explicitly use a
     * given event loop instance.
     *
     * This class takes an optional `?string $binary` parameter that can be used to
     * pass a custom PHP binary to use when spawning a child process. You can use a
     * `null` value here in order to automatically detect the current PHP binary. You
     * may want to pass a custom executable path if this automatic detection fails or
     * if you explicitly want to run the child process with a different PHP version or
     * environment than your parent process.
     *
     * ```php
     * // advanced usage: pass custom PHP binary to use when spawning child process
     * $factory = new Clue\React\SQLite\Factory(null, '/usr/bin/php6.0');
     * ```
     *
     * Or you may use this parameter to pass an empty PHP binary path which will
     * cause this project to not spawn a PHP child process for any database
     * interactions at all. In this case, using SQLite will block the main
     * process, but continues to provide the exact same async API. This can be
     * useful if concurrent execution is not needed, especially when running
     * behind a traditional web server (non-CLI SAPI).
     *
     * ```php
     * // advanced usage: empty binary path runs blocking SQLite in same process
     * $factory = new Clue\React\SQLite\Factory(null, '');
     * ```
     *
     * @param ?LoopInterface $loop
     * @param ?string $binary
     */
    public function __construct(LoopInterface $loop = null, $binary = null)
    {
        $this->loop = $loop ?: Loop::get();
        $this->bin = $binary === null ? $this->php() : $binary;

        // use socket I/O for Windows only, use faster process pipes everywhere else
        $this->useSocket = \DIRECTORY_SEPARATOR === '\\';
    }

    /**
     * Opens a new database connection for the given SQLite database file.
     *
     * This method returns a promise that will resolve with a `DatabaseInterface` on
     * success or will reject with an `Exception` on error. The SQLite extension
     * is inherently blocking, so this method will spawn an SQLite worker process
     * to run all SQLite commands and queries in a separate process without
     * blocking the main process. On Windows, it uses a temporary network socket
     * for this communication, on all other platforms, it communicates over
     * standard process I/O pipes.
     *
     * ```php
     * $factory->open('users.db')->then(function (DatabaseInterface $db) {
     *     // database ready
     *     // $db->query('INSERT INTO users (name) VALUES ("test")');
     *     // $db->quit();
     * }, function (Exception $e) {
     *     echo 'Error: ' . $e->getMessage() . PHP_EOL;
     * });
     * ```
     *
     * The `$filename` parameter is the path to the SQLite database file or
     * `:memory:` to create a temporary in-memory database. As of PHP 7.0.10, an
     * empty string can be given to create a private, temporary on-disk database.
     * Relative paths will be resolved relative to the current working directory,
     * so it's usually recommended to pass absolute paths instead to avoid any
     * ambiguity.
     *
     * ```php
     * $promise = $factory->open(__DIR__ . '/users.db');
     * ```
     *
     * The optional `$flags` parameter is used to determine how to open the
     * SQLite database. By default, open uses `SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE`.
     *
     * ```php
     * $factory->open('users.db', SQLITE3_OPEN_READONLY)->then(function (DatabaseInterface $db) {
     *     // database ready (read-only)
     *     // $db->quit();
     * }, function (Exception $e) {
     *     echo 'Error: ' . $e->getMessage() . PHP_EOL;
     * });
     * ```
     *
     * @param string $filename
     * @param ?int   $flags
     * @return PromiseInterface<DatabaseInterface> Resolves with DatabaseInterface instance or rejects with Exception
     */
    public function open($filename, $flags = null)
    {
        $filename = $this->resolve($filename);

        if ($this->bin === '') {
            try {
                return \React\Promise\resolve(new BlockingDatabase($filename, $flags));
            } catch (\Exception $e) {
                return \React\Promise\reject(new \RuntimeException($e->getMessage()) );
            } catch (\Error $e) { // @codeCoverageIgnore
                assert(\PHP_VERSION_ID >= 70000); // @codeCoverageIgnore
                return \React\Promise\reject(new \RuntimeException($e->getMessage())); // @codeCoverageIgnore
            }
        }

        return $this->useSocket ? $this->openSocketIo($filename, $flags) : $this->openProcessIo($filename, $flags);
    }

    /**
     * Opens a new database connection for the given SQLite database file.
     *
     * ```php
     * $db = $factory->openLazy('users.db');
     *
     * $db->query('INSERT INTO users (name) VALUES ("test")');
     * $db->quit();
     * ```
     *
     * This method immediately returns a "virtual" connection implementing the
     * [`DatabaseInterface`](#databaseinterface) that can be used to
     * interface with your SQLite database. Internally, it lazily creates the
     * underlying database process only on demand once the first request is
     * invoked on this instance and will queue all outstanding requests until
     * the underlying database is ready. Additionally, it will only keep this
     * underlying database in an "idle" state for 60s by default and will
     * automatically end the underlying database when it is no longer needed.
     *
     * From a consumer side this means that you can start sending queries to the
     * database right away while the underlying database process may still be
     * outstanding. Because creating this underlying process may take some
     * time, it will enqueue all oustanding commands and will ensure that all
     * commands will be executed in correct order once the database is ready.
     * In other words, this "virtual" database behaves just like a "real"
     * database as described in the `DatabaseInterface` and frees you from
     * having to deal with its async resolution.
     *
     * If the underlying database process fails, it will reject all
     * outstanding commands and will return to the initial "idle" state. This
     * means that you can keep sending additional commands at a later time which
     * will again try to open a new underlying database. Note that this may
     * require special care if you're using transactions that are kept open for
     * longer than the idle period.
     *
     * Note that creating the underlying database will be deferred until the
     * first request is invoked. Accordingly, any eventual connection issues
     * will be detected once this instance is first used. You can use the
     * `quit()` method to ensure that the "virtual" connection will be soft-closed
     * and no further commands can be enqueued. Similarly, calling `quit()` on
     * this instance when not currently connected will succeed immediately and
     * will not have to wait for an actual underlying connection.
     *
     * Depending on your particular use case, you may prefer this method or the
     * underlying `open()` method which resolves with a promise. For many
     * simple use cases, it may be easier to create a lazy connection.
     *
     * The `$filename` parameter is the path to the SQLite database file or
     * `:memory:` to create a temporary in-memory database. As of PHP 7.0.10, an
     * empty string can be given to create a private, temporary on-disk database.
     * Relative paths will be resolved relative to the current working directory,
     * so it's usually recommended to pass absolute paths instead to avoid any
     * ambiguity.
     *
     * ```php
     * $db = $factory->openLazy(__DIR__ . '/users.db');
     * ```
     *
     * The optional `$flags` parameter is used to determine how to open the
     * SQLite database. By default, open uses `SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE`.
     *
     * ```php
     * $db = $factory->openLazy('users.db', SQLITE3_OPEN_READONLY);
     * ```
     *
     * By default, this method will keep "idle" connection open for 60s and will
     * then end the underlying connection. The next request after an "idle"
     * connection ended will automatically create a new underlying connection.
     * This ensure you always get a "fresh" connection and as such should not be
     * confused with a "keepalive" or "heartbeat" mechanism, as this will not
     * actively try to probe the connection. You can explicitly pass a custom
     * idle timeout value in seconds (or use a negative number to not apply a
     * timeout) like this:
     *
     * ```php
     * $db = $factory->openLazy('users.db', null, ['idle' => 0.1]);
     * ```
     *
     * @param string $filename
     * @param ?int   $flags
     * @param array  $options
     * @return DatabaseInterface
     */
    public function openLazy($filename, $flags = null, array $options = [])
    {
        return new LazyDatabase($this->resolve($filename), $flags, $options, $this, $this->loop);
    }

    private function openProcessIo($filename, $flags = null)
    {
        $cwd = null;
        $worker = \dirname(__DIR__) . '/res/sqlite-worker.php';

        // launch worker process directly or inside Phar by mapping relative paths (covered by functional test suite)
        // @codeCoverageIgnoreStart
        if (\class_exists('Phar', false) && ($phar = \Phar::running(false)) !== '') {
            $worker = '-r' . 'Phar::loadPhar(' . var_export($phar, true) . ');require(' . \var_export($worker, true) . ');';
        } else {
            $cwd = __DIR__ . '/../res';
            $worker = \basename($worker);
        }
        // @codeCoverageIgnoreEnd
        $command = 'exec ' . \escapeshellarg($this->bin) . ' ' . escapeshellarg($worker);

        // Try to get list of all open FDs (Linux/Mac and others)
        $fds = @\scandir('/dev/fd');

        // Otherwise try temporarily duplicating file descriptors in the range 0-1024 (FD_SETSIZE).
        // This is known to work on more exotic platforms and also inside chroot
        // environments without /dev/fd. Causes many syscalls, but still rather fast.
        // @codeCoverageIgnoreStart
        if ($fds === false) {
            $fds = array();
            for ($i = 0; $i <= 1024; ++$i) {
                $copy = @\fopen('php://fd/' . $i, 'r');
                if ($copy !== false) {
                    $fds[] = $i;
                    \fclose($copy);
                }
            }
        }
        // @codeCoverageIgnoreEnd

        // launch process with default STDIO pipes, but inherit STDERR
        $pipes = array(
            array('pipe', 'r'),
            array('pipe', 'w'),
            \defined('STDERR') ? \STDERR : \fopen('php://stderr', 'w')
        );

        // do not inherit open FDs by explicitly overwriting existing FDs with dummy files.
        // Accessing /dev/null with null spec requires PHP 7.4+, older PHP versions may be restricted due to open_basedir, so let's reuse STDERR here.
        // additionally, close all dummy files in the child process again
        foreach ($fds as $fd) {
            if ($fd > 2) {
                $pipes[$fd] = \PHP_VERSION_ID >= 70400 ? ['null'] : $pipes[2];
                $command .= ' ' . $fd . '>&-';
            }
        }

        // default `sh` only accepts single-digit FDs, so run in bash if needed
        if ($fds && \max($fds) > 9) {
            $command = 'exec bash -c ' . \escapeshellarg($command);
        }

        $process = new Process($command, $cwd, null, $pipes);
        $process->start($this->loop);

        $db = new ProcessIoDatabase($process);
        $args = array($filename, $flags);

        return $db->send('open', $args)->then(function () use ($db) {
            return $db;
        }, function ($e) use ($db) {
            $db->close();
            throw $e;
        });
    }

    private function openSocketIo($filename, $flags = null)
    {
        $cwd = null;
        $worker = \dirname(__DIR__) . '/res/sqlite-worker.php';

        // launch worker process directly or inside Phar by mapping relative paths (covered by functional test suite)
        // @codeCoverageIgnoreStart
        if (\class_exists('Phar', false) && ($phar = \Phar::running(false)) !== '') {
            $worker = '-r' . 'Phar::loadPhar(' . var_export($phar, true) . ');require(' . \var_export($worker, true) . ');';
        } else {
            $cwd = __DIR__ . '/../res';
            $worker = \basename($worker);
        }
        // @codeCoverageIgnoreEnd
        $command = \escapeshellarg($this->bin) . ' ' . escapeshellarg($worker);

        // launch process without default STDIO pipes, but inherit STDERR
        $null = \DIRECTORY_SEPARATOR === '\\' ? 'nul' : '/dev/null';
        $pipes = array(
            array('file', $null, 'r'),
            array('file', $null, 'w'),
            \defined('STDERR') ? \STDERR : \fopen('php://stderr', 'w')
        );

        // start temporary socket on random address
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            // report error if temporary socket server can not be started (unlikely)
            // @codeCoverageIgnoreStart
            return \React\Promise\reject(
                new \RuntimeException('Unable to start temporary socket I/O server: ' . $errstr, $errno)
            );
            // @codeCoverageIgnoreEnd
        }

        // pass random server address to child process to connect back to parent process
        stream_set_blocking($server, false);
        $command .= ' ' . stream_socket_get_name($server, false);

        $process = new Process($command, $cwd, null, $pipes);
        $process->start($this->loop);

        $deferred = new Deferred(function () use ($process, $server) {
            $this->loop->removeReadStream($server);
            fclose($server);
            $process->terminate();

            throw new \RuntimeException('Opening database cancelled');
        });

        // time out after a few seconds if we don't receive a connection
        $timeout = $this->loop->addTimer(5.0, function () use ($server, $deferred, $process) {
            $this->loop->removeReadStream($server);
            fclose($server);
            $process->terminate();

            $deferred->reject(new \RuntimeException('Opening database socket timed out'));
        });

        $process->on('exit', function () use ($deferred, $server, $timeout) {
            $this->loop->cancelTimer($timeout);
            if (is_resource($server)) {
                $this->loop->removeReadStream($server);
                fclose($server);
            }

            $deferred->reject(new \RuntimeException('Database process died while setting up connection'));
        });

        $this->loop->addReadStream($server, function () use ($server, $timeout, $filename, $flags, $deferred, $process) {
            // accept once connection on server socket and stop server socket
            $this->loop->cancelTimer($timeout);
            $peer = stream_socket_accept($server, 0);
            $this->loop->removeReadStream($server);
            fclose($server);

            // use this one connection as fake process I/O streams
            $connection = new DuplexResourceStream($peer, $this->loop, -1);
            $process->stdin = $process->stdout = $connection;
            $connection->on('close', function () use ($process) {
                $process->terminate();
            });
            $process->on('exit', function () use ($connection) {
                $connection->close();
            });

            $db = new ProcessIoDatabase($process);
            $args = array($filename, $flags);

            $db->send('open', $args)->then(function () use ($deferred, $db) {
                $deferred->resolve($db);
            }, function ($e) use ($deferred, $db) {
                $db->close();
                $deferred->reject($e);
            });
        });

        return $deferred->promise();
    }

    /**
     * @param string $bin
     * @return string|null
     * @codeCoverageIgnore
     */
    private function which($bin)
    {
        foreach (\explode(\PATH_SEPARATOR, \getenv('PATH')) as $path) {
            if (@\is_executable($path . \DIRECTORY_SEPARATOR . $bin)) {
                return $path . \DIRECTORY_SEPARATOR . $bin;
            }
        }
        return null;
    }

    /**
     * @param string $filename
     * @return string
     */
    private function resolve($filename)
    {
        if ($filename !== '' && $filename !== ':memory:' && !\preg_match('/^\/|\w+\:\\\\/', $filename)) {
            $filename = \getcwd() . \DIRECTORY_SEPARATOR . $filename;
        }
        return $filename;
    }

    /**
     * @return string
     * @codeCoverageIgnore Covered by `/tests/FunctionalExampleTest.php` instead.
     */
    private function php()
    {
        $binary = 'php';
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'cli-server') {
            // use same PHP_BINARY in CLI mode, but do not use same binary for CGI/FPM
            $binary = \PHP_BINARY;
        } else {
            // if this is the php-cgi binary, check if we can execute the php binary instead
            $candidate = \str_replace('-cgi', '', \PHP_BINARY);
            if ($candidate !== \PHP_BINARY && @\is_executable($candidate)) {
                $binary = $candidate;
            }
        }

        // if `php` is a symlink to the php binary, use the shorter `php` name
        // this is purely cosmetic feature for the process list
        if ($binary !== 'php' && \realpath((string) $this->which('php')) === $binary) {
            $binary = 'php';
        }

        return $binary;
    }
}
