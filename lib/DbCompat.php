<?php

class DbCompatResult
{
    private $driverResult;
    private ?array $rows;
    private int $pointer = 0;
    public int $num_rows = 0;

    public function __construct($driverResult, ?array $rows = null)
    {
        $this->driverResult = $driverResult;
        $this->rows = $rows;
        if ($rows !== null) {
            $this->num_rows = count($rows);
        } elseif ($driverResult instanceof mysqli_result) {
            $this->num_rows = $driverResult->num_rows;
        }
    }

    public function fetch_assoc(): ?array
    {
        if ($this->rows !== null) {
            if ($this->pointer >= $this->num_rows) {
                return null;
            }
            return $this->rows[$this->pointer++];
        }

        if ($this->driverResult instanceof mysqli_result) {
            $row = $this->driverResult->fetch_assoc();
            return $row === null ? null : $row;
        }

        return null;
    }
}

class DbCompatStatement
{
    private DbCompatConnection $connection;
    private $driverStatement;
    private string $sql;
    private array $boundValues = [];
    private ?DbCompatResult $result = null;
    public string $error = '';
    public int $affected_rows = 0;

    public function __construct(DbCompatConnection $connection, $driverStatement, string $sql)
    {
        $this->connection = $connection;
        $this->driverStatement = $driverStatement;
        $this->sql = $sql;
    }

    public function bind_param(string $types, &...$vars): bool
    {
        if ($this->connection->getDialect() === 'mysql') {
            return $this->driverStatement->bind_param($types, ...$vars);
        }

        $this->boundValues = [];
        foreach ($vars as &$value) {
            $this->boundValues[] = &$value;
        }

        return true;
    }

    public function execute(): bool
    {
        if ($this->connection->getDialect() === 'mysql') {
            $ok = $this->driverStatement->execute();
            $this->error = $this->driverStatement->error ?? '';
            $this->affected_rows = (int) ($this->driverStatement->affected_rows ?? 0);
            $this->connection->affected_rows = $this->affected_rows;
            return $ok;
        }

        try {
            $params = [];
            foreach ($this->boundValues as $value) {
                $params[] = $value;
            }
            $ok = $this->driverStatement->execute($params);
            $this->affected_rows = $this->connection->normalizeAffectedRows($this->sql, $this->driverStatement->rowCount());
            $this->connection->affected_rows = $this->affected_rows;
            $this->result = $this->buildResult();
            $this->error = '';
            return $ok;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            $this->connection->error = $this->error;
            return false;
        }
    }

    public function get_result(): DbCompatResult
    {
        if ($this->connection->getDialect() === 'mysql') {
            return new DbCompatResult($this->driverStatement->get_result());
        }

        return $this->result ?? new DbCompatResult(null, []);
    }

    public function close(): bool
    {
        if ($this->connection->getDialect() === 'mysql') {
            return $this->driverStatement->close();
        }

        $this->driverStatement = null;
        return true;
    }

    private function buildResult(): DbCompatResult
    {
        $columnCount = $this->driverStatement->columnCount();
        if ($columnCount <= 0) {
            return new DbCompatResult(null, []);
        }

        $rows = $this->driverStatement->fetchAll(PDO::FETCH_ASSOC);
        return new DbCompatResult(null, $rows);
    }
}

class DbCompatConnection
{
    private string $dialect;
    private $driver;
    public ?string $connect_error = null;
    public string $error = '';
    public int $affected_rows = 0;

    private function __construct(string $dialect, $driver)
    {
        $this->dialect = $dialect;
        $this->driver = $driver;
    }

    public static function fromConfig(array $config): self
    {
        $dialect = strtolower((string) ($config['dialect'] ?? 'mysql'));

        if ($dialect === 'pgsql' || $dialect === 'postgres' || $dialect === 'postgresql') {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? '5432',
                $config['dbname'] ?? '',
                $config['sslmode'] ?? 'require'
            );

            try {
                $pdo = new PDO($dsn, $config['user'] ?? '', $config['password'] ?? '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $pdo->exec("SET NAMES 'UTF8'");
                return new self('pgsql', $pdo);
            } catch (Throwable $e) {
                $conn = new self('pgsql', null);
                $conn->connect_error = $e->getMessage();
                $conn->error = $e->getMessage();
                return $conn;
            }
        }

        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @new mysqli(
            $config['host'] ?? '127.0.0.1',
            $config['user'] ?? '',
            $config['password'] ?? '',
            $config['dbname'] ?? '',
            (int) ($config['port'] ?? 3306)
        );

        $conn = new self('mysql', $mysqli);
        if ($mysqli->connect_error) {
            $conn->connect_error = $mysqli->connect_error;
            $conn->error = $mysqli->connect_error;
            return $conn;
        }

        return $conn;
    }

    public function getDialect(): string
    {
        return $this->dialect;
    }

    public function query(string $sql)
    {
        $sql = $this->transformSql($sql);

        if ($this->dialect === 'mysql') {
            $result = $this->driver->query($sql);
            $this->error = $result === false ? (string) $this->driver->error : '';
            $this->affected_rows = (int) $this->driver->affected_rows;
            if ($result instanceof mysqli_result) {
                return new DbCompatResult($result);
            }
            return $result;
        }

        try {
            $statement = $this->driver->query($sql);
            $this->affected_rows = $this->normalizeAffectedRows($sql, $statement->rowCount());
            if ($statement->columnCount() > 0) {
                return new DbCompatResult(null, $statement->fetchAll(PDO::FETCH_ASSOC));
            }
            return true;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function prepare(string $sql)
    {
        $sql = $this->transformSql($sql);

        if ($this->dialect === 'mysql') {
            $statement = $this->driver->prepare($sql);
            if ($statement === false) {
                $this->error = (string) $this->driver->error;
                return false;
            }
            return new DbCompatStatement($this, $statement, $sql);
        }

        try {
            $statement = $this->driver->prepare($sql);
            return new DbCompatStatement($this, $statement, $sql);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function set_charset(string $charset): bool
    {
        if ($this->dialect === 'mysql') {
            return $this->driver->set_charset($charset);
        }

        try {
            $this->driver->exec("SET client_encoding TO '" . strtoupper($charset) . "'");
            return true;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function begin_transaction(): bool
    {
        if ($this->dialect === 'mysql') {
            return $this->driver->begin_transaction();
        }

        return $this->driver->beginTransaction();
    }

    public function commit(): bool
    {
        if ($this->dialect === 'mysql') {
            return $this->driver->commit();
        }

        return $this->driver->commit();
    }

    public function rollback(): bool
    {
        if ($this->dialect === 'mysql') {
            return $this->driver->rollback();
        }

        if (!$this->driver->inTransaction()) {
            return true;
        }

        return $this->driver->rollBack();
    }

    public function real_escape_string(string $value): string
    {
        if ($this->dialect === 'mysql') {
            return $this->driver->real_escape_string($value);
        }

        $quoted = $this->driver->quote($value);
        return substr($quoted, 1, -1);
    }

    public function normalizeAffectedRows(string $sql, int $rowCount): int
    {
        if ($this->dialect !== 'pgsql') {
            return $rowCount;
        }

        if (stripos($sql, 'insert into') === 0 && stripos($sql, 'on conflict') !== false) {
            return $rowCount > 0 ? 1 : 0;
        }

        return $rowCount;
    }

    private function transformSql(string $sql): string
    {
        if ($this->dialect !== 'pgsql') {
            return $sql;
        }

        $sql = trim($sql);

        if (stripos($sql, 'INSERT INTO NTP_Subsektor') === 0) {
            return preg_replace(
                '/ON DUPLICATE KEY UPDATE\s+nilai\s*=\s*VALUES\(nilai\)/i',
                'ON CONFLICT (prov, tahun, bulan, subsektor, rincian) DO UPDATE SET nilai = EXCLUDED.nilai',
                $sql
            );
        }

        if (stripos($sql, 'INSERT INTO Andil_NTP') === 0) {
            return preg_replace(
                '/ON DUPLICATE KEY UPDATE\s+andil\s*=\s*VALUES\(andil\)/i',
                'ON CONFLICT (subsektor, prov, jnsbrg, komoditi, kel, rincian, kode_bulan, tahun) DO UPDATE SET andil = EXCLUDED.andil',
                $sql
            );
        }

        if (stripos($sql, 'INSERT INTO BRS_Alasan_Subsektor') === 0) {
            return preg_replace(
                '/ON DUPLICATE KEY UPDATE\s+alasan\s*=\s*VALUES\(alasan\)/i',
                'ON CONFLICT (period_tahun, period_bulan, subsektor_key) DO UPDATE SET alasan = EXCLUDED.alasan',
                $sql
            );
        }

        return $sql;
    }
}
