<?php
namespace OmekaTheme\Helper;

use Laminas\View\Helper\AbstractHelper;

/**
 * Diagnostic performance probe (optimization.md Phase 0).
 *
 * Dumps a plain-text timing/query report and stops the request when a debug
 * query param is present, together with the matching probe token. Two call
 * sites use it:
 *
 *   - home page      : ?hgdebug=N&probe=TOKEN   (view/common/page-template/home.phtml)
 *   - search results : ?fsdebug=N&probe=TOKEN   (view/omeka/site/item/browse.phtml)
 *
 * The token gates the dump because it reports the database host and name and
 * the PHP configuration; without it (or with a wrong one) the page renders
 * normally and nothing is disclosed.
 *
 * N is the stage to stop after; stages are numbered per call site. The dump
 * reports every mark twice - relative to the template start and relative to
 * PHP's REQUEST_TIME_FLOAT - so the framework bootstrap that happens before the
 * template runs is visible as its own line. It also installs a Doctrine
 * DebugStack SQL logger, so each stage carries a query count and summed query
 * time, and prints an environment block (opcache / APCu / Xdebug / Doctrine
 * cache drivers / DB round-trip latency).
 *
 * Diagnostics only: nothing here runs unless the debug param is in the query
 * string, and the probe always exits before any markup is produced.
 */
class PerfProbe extends AbstractHelper
{
    /** Number of SELECT 1 round trips used to measure DB latency. */
    const PING_COUNT = 20;

    /**
     * Shared secret required in ?probe= before the probe will arm. Rotate by
     * editing this constant; there is no other copy of it.
     */
    const TOKEN = 'e16430a15fd8';

    /** @var int Requested stage to stop after; 0 = probe inactive. */
    protected $level = 0;

    /** @var float Template-relative time origin. */
    protected $start = 0.0;

    /** @var float Request time origin (REQUEST_TIME_FLOAT). */
    protected $reqStart = 0.0;

    /** @var array[] Each: [absolute time, label, detail, query count|null, query seconds|null]. */
    protected $marks = [];

    /** @var \Doctrine\DBAL\Logging\DebugStack|null */
    protected $sqlLogger;

    /** @var \Doctrine\ORM\EntityManager|null */
    protected $em;

    /** @var object[] Attached mark providers (marks() + startedAt()). */
    protected $providers = [];

    public function __invoke()
    {
        return $this;
    }

    /**
     * Arm the probe. Reads the stage from $param in the query string and
     * requires ?probe= to match TOKEN; without both, every other method is a
     * no-op and the page renders normally.
     *
     * @param string $param Query param carrying the stage number.
     * @return self
     */
    public function begin($param)
    {
        $this->start = microtime(true);
        $this->reqStart = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : $this->start;
        $params = $this->getView()->params();
        $level = (int) $params->fromQuery($param, 0);
        $token = (string) $params->fromQuery('probe', '');
        if ($level > 0 && hash_equals(self::TOKEN, $token)) {
            $this->level = $level;
            $this->installSqlLogger();
        }
        return $this;
    }

    /** True when a debug stage was requested, so call sites can skip probe-only work. */
    public function active()
    {
        return $this->level > 0;
    }

    /**
     * Attach a helper that keeps its own marks (HomeGraph). The object must
     * expose marks() and startedAt(); its marks are merged into the dump.
     *
     * @param object $provider
     * @return self
     */
    public function attach($provider)
    {
        if ($this->level > 0 && is_object($provider)
            && method_exists($provider, 'marks') && method_exists($provider, 'startedAt')
        ) {
            $this->providers[] = $provider;
        }
        return $this;
    }

    /**
     * Record a mark without stopping.
     *
     * @param string $label
     * @param mixed $detail Optional count or note.
     * @return self
     */
    public function mark($label, $detail = null)
    {
        if ($this->level > 0) {
            $this->marks[] = [microtime(true), $label, $detail, $this->queryCount(), $this->querySeconds()];
        }
        return $this;
    }

    /**
     * Record a mark and, if this is the requested stage or later, dump and exit.
     *
     * @param int $stage This call site's stage number.
     * @param string $label
     * @param mixed $detail
     */
    public function stage($stage, $label, $detail = null)
    {
        if (!$this->level) {
            return;
        }
        $this->mark($label, $detail);
        if ($this->level > $stage) {
            return;
        }
        $this->dump(sprintf('stopped after %s%s', $label, $detail === null ? '' : " ($detail)"));
        exit;
    }

    // ---- output ------------------------------------------------------------

    /**
     * Print the report as text/plain, discarding any buffered markup.
     *
     * @param string $reason Why the request stopped here.
     */
    public function dump($reason)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }

        printf("=== perf probe: %s ===\n\n", $reason);
        printf("%9s %9s %6s %6s %9s %9s  %s\n", 'req', 'tpl', '+q', 'q', '+sql', 'sql', 'stage');
        printf(
            "%9s %9s %6s %6s %9s %9s  %s\n",
            sprintf('%.3fs', $this->start - $this->reqStart),
            sprintf('%.3fs', 0),
            '-', '-', '-', '-',
            '== bootstrap (request start -> template start)'
        );

        $prevQ = 0;
        $prevSql = 0.0;
        foreach ($this->collectRows() as $row) {
            list($abs, $label, $detail, $qCount, $qSecs) = $row;
            $hasQ = $qCount !== null;
            printf(
                "%9s %9s %6s %6s %9s %9s  %s%s\n",
                sprintf('%.3fs', $abs - $this->reqStart),
                sprintf('%.3fs', $abs - $this->start),
                $hasQ ? '+' . ($qCount - $prevQ) : '-',
                $hasQ ? (string) $qCount : '-',
                $hasQ ? sprintf('%.3fs', $qSecs - $prevSql) : '-',
                $hasQ ? sprintf('%.3fs', $qSecs) : '-',
                $label,
                $detail === null ? '' : " ($detail)"
            );
            if ($hasQ) {
                $prevQ = $qCount;
                $prevSql = $qSecs;
            }
        }

        printf("\ntotal request time so far: %.3fs\n", microtime(true) - $this->reqStart);
        printf("peak memory (real): %.1f MB\n", memory_get_peak_usage(true) / 1048576);

        $this->dumpQueries();
        $this->dumpEnvironment();
    }

    /** Merge own marks with attached providers', ordered by absolute time. */
    protected function collectRows()
    {
        $rows = $this->marks;
        foreach ($this->providers as $provider) {
            $base = (float) $provider->startedAt();
            foreach ($provider->marks() as $m) {
                // Provider marks carry no query snapshot: printed with dashes.
                $rows[] = [$base + (float) $m[0], $m[1], isset($m[2]) ? $m[2] : null, null, null];
            }
        }
        usort($rows, function ($a, $b) {
            return $a[0] <=> $b[0];
        });
        return $rows;
    }

    /** Query totals, the slowest statements and the most repeated ones. */
    protected function dumpQueries()
    {
        if (!$this->sqlLogger) {
            echo "\nSQL logger: unavailable (no DebugStack / entity manager)\n";
            return;
        }
        $queries = $this->sqlLogger->queries;
        printf("\nSQL: %d queries, %.3fs total (logged from template start)\n", count($queries), $this->querySeconds());

        $slowest = $queries;
        usort($slowest, function ($a, $b) {
            return $b['executionMS'] <=> $a['executionMS'];
        });
        echo "\nslowest queries:\n";
        foreach (array_slice($slowest, 0, 5) as $q) {
            printf("  %7.1fms  %s\n", $q['executionMS'] * 1000, $this->shortSql($q['sql']));
        }

        $byShape = [];
        foreach ($queries as $q) {
            $shape = $this->shortSql($q['sql']);
            if (!isset($byShape[$shape])) {
                $byShape[$shape] = [0, 0.0];
            }
            $byShape[$shape][0]++;
            $byShape[$shape][1] += $q['executionMS'];
        }
        uasort($byShape, function ($a, $b) {
            return $b[0] <=> $a[0];
        });
        echo "\nmost repeated queries:\n";
        $shown = 0;
        foreach ($byShape as $shape => $info) {
            printf("  %4dx %7.1fms  %s\n", $info[0], $info[1] * 1000, $shape);
            if (++$shown >= 5) {
                break;
            }
        }
    }

    /** Collapse whitespace and truncate a statement for the report. */
    protected function shortSql($sql)
    {
        $sql = preg_replace('/\s+/', ' ', (string) $sql);
        return mb_strlen($sql) > 150 ? mb_substr($sql, 0, 150) . ' ...' : $sql;
    }

    /** PHP extensions, Doctrine cache drivers and DB latency (optimization.md 0.3). */
    protected function dumpEnvironment()
    {
        echo "\n=== environment ===\n";
        printf("php: %s (%s)\n", PHP_VERSION, PHP_SAPI);

        if (extension_loaded('xdebug')) {
            printf("xdebug: LOADED (mode=%s)\n", ini_get('xdebug.mode') ?: '?');
        } else {
            echo "xdebug: not loaded\n";
        }

        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            if (is_array($status) && !empty($status['opcache_enabled'])) {
                $stats = isset($status['opcache_statistics']) ? $status['opcache_statistics'] : [];
                $mem = isset($status['memory_usage']) ? $status['memory_usage'] : [];
                $used = isset($mem['used_memory']) ? $mem['used_memory'] : 0;
                $free = isset($mem['free_memory']) ? $mem['free_memory'] : 0;
                printf(
                    "opcache: enabled, hit rate %.1f%%, %d cached scripts, %.1f/%.1f MB used, %d oom restarts\n",
                    isset($stats['opcache_hit_rate']) ? $stats['opcache_hit_rate'] : 0,
                    isset($stats['num_cached_scripts']) ? $stats['num_cached_scripts'] : 0,
                    $used / 1048576,
                    ($used + $free) / 1048576,
                    isset($stats['oom_restarts']) ? $stats['oom_restarts'] : 0
                );
            } else {
                echo "opcache: DISABLED for this SAPI\n";
            }
        } else {
            echo "opcache: extension not loaded\n";
        }

        if (function_exists('apcu_enabled')) {
            printf("apcu: %s\n", apcu_enabled() ? 'enabled' : 'loaded but disabled');
        } else {
            echo "apcu: NOT AVAILABLE\n";
        }

        // Raw ini values. These stay readable even when opcache.restrict_api
        // blocks opcache_get_status(), and ini_get() returns false outright for
        // settings whose extension is not installed.
        echo "\nini settings:\n";
        $inis = [
            'opcache.enable', 'opcache.enable_cli', 'opcache.memory_consumption',
            'opcache.interned_strings_buffer', 'opcache.max_accelerated_files',
            'opcache.validate_timestamps', 'opcache.revalidate_freq',
            'opcache.save_comments', 'opcache.jit', 'opcache.restrict_api',
            'apc.enabled', 'apc.shm_size',
            'realpath_cache_size', 'realpath_cache_ttl',
            'memory_limit', 'max_execution_time',
        ];
        foreach ($inis as $ini) {
            $value = ini_get($ini);
            if ($value === false) {
                $value = '(not set - extension absent?)';
            } elseif ($value === '') {
                $value = '(empty)';
            }
            printf("  %-33s %s\n", $ini, $value);
        }

        // Phase 4 needs somewhere writable for a global cache; if files/ is not
        // writable by the web user the Settings table is the fallback.
        if (defined('OMEKA_PATH')) {
            $filesDir = OMEKA_PATH . '/files';
            printf(
                "\nfiles dir: %s  exists=%s  writable=%s\n",
                $filesDir,
                is_dir($filesDir) ? 'yes' : 'no',
                is_writable($filesDir) ? 'yes' : 'NO'
            );
        }

        $this->dumpDoctrineEnvironment();
    }

    /** Doctrine cache drivers plus a SELECT 1 round-trip loop. */
    protected function dumpDoctrineEnvironment()
    {
        if (!$this->em) {
            echo "doctrine: entity manager unavailable\n";
            return;
        }
        $config = $this->em->getConfiguration();
        foreach (['Metadata', 'Query', 'Result'] as $kind) {
            $driver = null;
            try {
                $psr6 = 'get' . $kind . 'Cache';
                $legacy = 'get' . $kind . 'CacheImpl';
                if (method_exists($config, $psr6)) {
                    $driver = $config->$psr6();
                }
                if (!$driver && method_exists($config, $legacy)) {
                    $driver = $config->$legacy();
                }
            } catch (\Throwable $e) {
                $driver = null;
            }
            printf("doctrine %s cache: %s\n", strtolower($kind), $driver ? get_class($driver) : 'none');
        }
        printf("doctrine proxy autogenerate: %s\n", var_export($config->getAutoGenerateProxyClasses(), true));

        try {
            $connection = $this->em->getConnection();
            $params = $connection->getParams();
            printf(
                "db: driver=%s host=%s port=%s name=%s\n",
                isset($params['driver']) ? $params['driver'] : '?',
                isset($params['host']) ? $params['host'] : (isset($params['unix_socket']) ? 'socket' : '?'),
                isset($params['port']) ? $params['port'] : '-',
                isset($params['dbname']) ? $params['dbname'] : '?'
            );
            // Round-trip latency. The logger is paused so these do not pollute
            // the query report above.
            $wasEnabled = $this->sqlLogger ? $this->sqlLogger->enabled : false;
            if ($this->sqlLogger) {
                $this->sqlLogger->enabled = false;
            }
            $times = [];
            for ($i = 0; $i < self::PING_COUNT; $i++) {
                $t = microtime(true);
                $statement = $connection->executeQuery('SELECT 1');
                method_exists($statement, 'fetchOne') ? $statement->fetchOne() : $statement->fetchColumn();
                $times[] = (microtime(true) - $t) * 1000;
            }
            if ($this->sqlLogger) {
                $this->sqlLogger->enabled = $wasEnabled;
            }
            sort($times);
            printf(
                "db round trip (SELECT 1 x%d): min %.2fms  median %.2fms  max %.2fms\n",
                self::PING_COUNT,
                $times[0],
                $times[(int) floor(count($times) / 2)],
                $times[count($times) - 1]
            );
        } catch (\Throwable $e) {
            printf("db probe failed: %s\n", $e->getMessage());
        }
    }

    // ---- SQL logging -------------------------------------------------------

    protected function installSqlLogger()
    {
        $this->em = $this->entityManager();
        if (!$this->em || !class_exists('\Doctrine\DBAL\Logging\DebugStack')) {
            return;
        }
        $this->sqlLogger = new \Doctrine\DBAL\Logging\DebugStack();
        $this->em->getConnection()->getConfiguration()->setSQLLogger($this->sqlLogger);
        // Discard whatever reaching the entity manager cost, so counts start at zero.
        $this->sqlLogger->queries = [];
        $this->sqlLogger->currentQuery = 0;
    }

    protected function queryCount()
    {
        return $this->sqlLogger ? count($this->sqlLogger->queries) : null;
    }

    protected function querySeconds()
    {
        if (!$this->sqlLogger) {
            return null;
        }
        $total = 0.0;
        foreach ($this->sqlLogger->queries as $q) {
            $total += (float) $q['executionMS'];
        }
        return $total;
    }

    /**
     * Reach the entity manager from a view helper. Themes have no service
     * locator of their own, so try the helper plugin manager first and fall back
     * to a resource representation, which exposes the locator (the same route
     * the AdvancedSearch module uses).
     *
     * @return \Doctrine\ORM\EntityManager|null
     */
    protected function entityManager()
    {
        $view = $this->getView();
        $services = null;
        try {
            $plugins = $view->getHelperPluginManager();
            if (method_exists($plugins, 'getServiceLocator')) {
                $services = $plugins->getServiceLocator();
            } else {
                $property = new \ReflectionProperty(get_class($plugins), 'creationContext');
                $property->setAccessible(true);
                $services = $property->getValue($plugins);
            }
        } catch (\Throwable $e) {
            $services = null;
        }
        if (!$services || !$services->has('Omeka\EntityManager')) {
            try {
                $services = $view->api()->read('vocabularies', 1)->getContent()->getServiceLocator();
            } catch (\Throwable $e) {
                return null;
            }
        }
        try {
            return $services->get('Omeka\EntityManager');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
