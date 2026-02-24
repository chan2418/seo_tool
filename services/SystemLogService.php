<?php

require_once __DIR__ . '/../models/SystemLogModel.php';

class SystemLogService
{
    private SystemLogModel $model;
    private string $file;

    public function __construct(?SystemLogModel $model = null)
    {
        $this->model = $model ?? new SystemLogModel();
        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }
        $this->file = $storageDir . '/system_errors.log';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, '');
        }
    }

    public function info(string $source, string $message, array $context = [], ?int $userId = null, ?int $projectId = null): void
    {
        $this->log('info', $source, $message, $context, $userId, $projectId);
    }

    public function warning(string $source, string $message, array $context = [], ?int $userId = null, ?int $projectId = null): void
    {
        $this->log('warning', $source, $message, $context, $userId, $projectId);
    }

    public function error(string $source, string $message, array $context = [], ?int $userId = null, ?int $projectId = null): void
    {
        $this->log('error', $source, $message, $context, $userId, $projectId);
    }

    public function critical(string $source, string $message, array $context = [], ?int $userId = null, ?int $projectId = null): void
    {
        $this->log('critical', $source, $message, $context, $userId, $projectId);
    }

    public function logException(string $source, Throwable $error, array $context = [], ?int $userId = null, ?int $projectId = null): void
    {
        $context['exception'] = [
            'message' => $error->getMessage(),
            'code' => (string) $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ];
        $this->log('error', $source, $error->getMessage(), $context, $userId, $projectId);
    }

    public function log(
        string $level,
        string $source,
        string $message,
        array $context = [],
        ?int $userId = null,
        ?int $projectId = null
    ): void {
        $this->model->create($level, $source, $message, $context, $userId, $projectId);
        $line = date('Y-m-d H:i:s') . ' [' . strtoupper($level) . '] '
            . '[' . $source . '] '
            . $message;
        if (!empty($context)) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }
        file_put_contents($this->file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

