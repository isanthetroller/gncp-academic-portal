<?php
/**
 * GNCP Base Station Service
 * Standardized base class for all station domain services.
 * Enforces uniform transaction handling, error logging, and API payload contracts.
 */

abstract class BaseStationService {
    protected PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Executes a database operation within an explicit ACID transaction.
     */
    protected function transaction(callable $callback) {
        try {
            $this->pdo->beginTransaction();
            $result = $callback($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (function_exists('logAppError')) {
                logAppError(get_called_class() . ' Transaction Error: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
            }
            return $this->error('Database transaction failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Formats a standard success response payload.
     */
    protected function success(string $message = 'Success', array $data = [], int $code = 200): array {
        return [
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'code'    => $code
        ];
    }

    /**
     * Formats a standard error response payload.
     */
    protected function error(string $message = 'Error', int $code = 400, array $errors = []): array {
        return [
            'success' => false,
            'message' => $message,
            'code'    => $code,
            'errors'  => $errors
        ];
    }
}
