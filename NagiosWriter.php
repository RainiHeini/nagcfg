<?php

class NagiosWriter
{
    private array $config;
    private string $transactionId = '';
    private array $backedUpFiles = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function setTransactionId(string $id): void
    {
        $this->transactionId = $id;
    }

    /**
     * Save (update) an existing object in a .cfg file.
     *
     * @return array{success: bool, message: string}
     */
    public function saveObject(
        string $file,
        int    $lineStart,
        int    $lineEnd,
        string $type,
        array  $directives,
        int    $expectedMtime
    ): array {
        if ($this->config['readonly'] ?? false) {
            return ['success' => false, 'message' => 'Read-only mode active.'];
        }

        if (!is_writable($file)) {
            return ['success' => false, 'message' => 'File not writable:' . $file];
        }

        // Race condition check
        clearstatcache(true, $file);
        if (filemtime($file) !== $expectedMtime) {
            return ['success' => false, 'message' => 'File was modified externally. Please reload the page and try again.'];
        }

        // Backup
        $backup = $this->backup($file);
        if (!$backup['success']) {
            return $backup;
        }

        // Read file
        $lines = file($file);
        if ($lines === false) {
            return ['success' => false, 'message' => 'Could not read file.'];
        }

        // Build new block
        $indent = $this->detectIndent($lines, $lineStart - 1, $lineEnd - 1);
        $newBlock = $this->buildBlock($type, $directives, $indent);

        // Replace lines (line numbers are 1-based)
        $before = array_slice($lines, 0, $lineStart - 1);
        $after  = array_slice($lines, $lineEnd);
        $content = implode('', $before) . $newBlock . implode('', $after);

        // Atomic write
        return $this->atomicWrite($file, $content);
    }

    /**
     * Append a new object to a .cfg file.
     *
     * @return array{success: bool, message: string}
     */
    public function appendObject(string $file, string $type, array $directives): array
    {
        if ($this->config['readonly'] ?? false) {
            return ['success' => false, 'message' => 'Read-only mode active.'];
        }

        if (!file_exists($file)) {
            // Create new file
            $content = '';
        } else {
            if (!is_writable($file)) {
                return ['success' => false, 'message' => 'File not writable:' . $file];
            }
            $content = file_get_contents($file);
            if ($content === false) {
                return ['success' => false, 'message' => 'Could not read file.'];
            }

            // Backup existing file
            $backup = $this->backup($file);
            if (!$backup['success']) {
                return $backup;
            }
        }

        // Detect indent from existing content or use tab as default
        $indent = "\t";
        if ($content !== '') {
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                if (preg_match('/^(\t+)\S/', $line, $m)) {
                    $indent = $m[1];
                    break;
                } elseif (preg_match('/^( {2,})\S/', $line, $m)) {
                    $indent = $m[1];
                    break;
                }
            }
        }

        // Ensure file ends with newline, then add two blank lines
        $content = rtrim($content) . "\n\n\n";
        $content .= $this->buildBlock($type, $directives, $indent);

        return $this->atomicWrite($file, $content);
    }

    /**
     * Delete an object from a .cfg file.
     *
     * @return array{success: bool, message: string}
     */
    public function deleteObject(
        string $file,
        int    $lineStart,
        int    $lineEnd,
        int    $expectedMtime,
        string $comments = ''
    ): array {
        if ($this->config['readonly'] ?? false) {
            return ['success' => false, 'message' => 'Read-only mode active.'];
        }

        if (!is_writable($file)) {
            return ['success' => false, 'message' => 'File not writable:' . $file];
        }

        // Race condition check
        clearstatcache(true, $file);
        if (filemtime($file) !== $expectedMtime) {
            return ['success' => false, 'message' => 'File was modified externally. Please reload the page.'];
        }

        // Backup
        $backup = $this->backup($file);
        if (!$backup['success']) {
            return $backup;
        }

        $lines = file($file);
        if ($lines === false) {
            return ['success' => false, 'message' => 'Could not read file.'];
        }

        // Calculate how many comment lines to also remove
        $commentLineCount = 0;
        if ($comments !== '') {
            $commentLineCount = substr_count($comments, "\n");
            // Don't go before line 1
            if ($lineStart - $commentLineCount < 1) {
                $commentLineCount = $lineStart - 1;
            }
        }

        $removeStart = $lineStart - 1 - $commentLineCount;
        $removeEnd = $lineEnd;

        // Also remove trailing blank lines after the block
        while ($removeEnd < count($lines) && trim($lines[$removeEnd]) === '') {
            $removeEnd++;
        }

        $before = array_slice($lines, 0, $removeStart);
        $after  = array_slice($lines, $removeEnd);
        $content = implode('', $before) . implode('', $after);

        return $this->atomicWrite($file, $content);
    }

    /**
     * Save raw file content.
     *
     * @return array{success: bool, message: string}
     */
    public function saveRaw(string $file, string $content, int $expectedMtime): array
    {
        if ($this->config['readonly'] ?? false) {
            return ['success' => false, 'message' => 'Read-only mode active.'];
        }

        if (!is_writable($file)) {
            return ['success' => false, 'message' => 'File not writable:' . $file];
        }

        clearstatcache(true, $file);
        if (filemtime($file) !== $expectedMtime) {
            return ['success' => false, 'message' => 'File was modified externally. Please reload the page.'];
        }

        $backup = $this->backup($file);
        if (!$backup['success']) {
            return $backup;
        }

        return $this->atomicWrite($file, $content);
    }

    /**
     * Reload Nagios via command pipe.
     *
     * @return array{success: bool, message: string}
     */
    public function reloadNagios(): array
    {
        if ($this->config['readonly'] ?? false) {
            return ['success' => false, 'message' => 'Read-only mode active.'];
        }

        $cmdFile = $this->config['nagios_cmd'];

        if (!file_exists($cmdFile)) {
            return ['success' => false, 'message' => 'Command pipe not found. Is Nagios running?'];
        }

        if (filetype($cmdFile) !== 'fifo') {
            return ['success' => false, 'message' => 'Command pipe is not a named pipe. Nagios restart required.'];
        }

        if (!is_writable($cmdFile)) {
            return ['success' => false, 'message' => 'Command pipe not writable:' . $cmdFile];
        }

        $timestamp = time();
        $cmd = "[$timestamp] RESTART_PROGRAM;$timestamp\n";

        $result = @file_put_contents($cmdFile, $cmd, FILE_APPEND);
        if ($result === false) {
            return ['success' => false, 'message' => 'Error writing to command pipe.'];
        }

        return ['success' => true, 'message' => 'Nagios reload triggered.'];
    }

    // ── Private Helpers ─────────────────────────────────────────────────

    /**
     * Create a backup of a file.
     */
    public function backup(string $file): array
    {
        // Skip if already backed up in this transaction
        $realFile = realpath($file);
        if ($realFile && $this->transactionId !== '' && in_array($realFile, $this->backedUpFiles, true)) {
            return ['success' => true, 'message' => 'Already backed up in this transaction.'];
        }

        $backupDir = rtrim($this->config['backup_dir'], '/');

        if (!is_dir($backupDir)) {
            if (!@mkdir($backupDir, 0775, true)) {
                return ['success' => false, 'message' => 'Could not create backup directory:' . $backupDir];
            }
        }

        if (!is_writable($backupDir)) {
            return ['success' => false, 'message' => 'Backup directory not writable:' . $backupDir];
        }

        $basename = basename($file);
        $timestamp = date('Y-m-d_H-i-s');
        $txSuffix = $this->transactionId !== '' ? '_' . $this->transactionId : '';
        $backupFile = "$backupDir/{$basename}_{$timestamp}{$txSuffix}.bak";

        if (!@copy($file, $backupFile)) {
            return ['success' => false, 'message' => 'Could not create backup:' . $backupFile];
        }

        if ($realFile) {
            $this->backedUpFiles[] = $realFile;
        }

        $this->rotateBackups($backupDir);

        return ['success' => true, 'message' => 'Backup created:' . $backupFile];
    }

    /**
     * Rotate old backups, keeping only max_backups per original file.
     */
    private function rotateBackups(string $backupDir): void
    {
        $maxBackups = $this->config['max_backups'] ?? 10;

        // Group backups by original filename
        $groups = [];
        $files = glob("$backupDir/*.bak");
        if ($files === false) return;

        foreach ($files as $f) {
            // Extract original name: name.cfg_2024-01-01_12-00-00[_txid].bak → name.cfg
            if (preg_match('/^(.+\.cfg)_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}(_[a-f0-9]+)?\.bak$/', basename($f), $m)) {
                $groups[$m[1]][] = $f;
            }
        }

        foreach ($groups as $origName => $backups) {
            if (count($backups) <= $maxBackups) continue;

            // Sort by mtime, oldest first
            usort($backups, fn($a, $b) => filemtime($a) - filemtime($b));

            $toDelete = count($backups) - $maxBackups;
            for ($i = 0; $i < $toDelete; $i++) {
                @unlink($backups[$i]);
            }
        }
    }

    /**
     * Write content atomically using temp file + rename.
     */
    private function atomicWrite(string $file, string $content): array
    {
        // Normalize line endings to Unix (LF only)
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);

        $dir = dirname($file);
        $tmpFile = tempnam($dir, '.nagcfg_');

        if ($tmpFile === false) {
            // Fallback: use backup dir for temp file
            $tmpFile = tempnam($this->config['backup_dir'], '.nagcfg_');
            if ($tmpFile === false) {
                return ['success' => false, 'message' => 'Could not create temp file.'];
            }
        }

        if (file_put_contents($tmpFile, $content) === false) {
            @unlink($tmpFile);
            return ['success' => false, 'message' => 'Could not write temp file.'];
        }

        // Preserve original permissions and ownership
        if (file_exists($file)) {
            $perms = fileperms($file);
            @chmod($tmpFile, $perms);
            $stat = @stat($file);
            if ($stat) {
                @chown($tmpFile, $stat['uid']);
                @chgrp($tmpFile, $stat['gid']);
            }
        }

        if (!@rename($tmpFile, $file)) {
            // rename fails across filesystems, fallback to copy
            if (!@copy($tmpFile, $file)) {
                @unlink($tmpFile);
                return ['success' => false, 'message' => 'Could not write file.'];
            }
            @unlink($tmpFile);
        }

        return ['success' => true, 'message' => 'File saved.'];
    }

    /**
     * Detect the indentation style used in a file section.
     */
    private function detectIndent(array $lines, int $blockStart, int $blockEnd): string
    {
        $tabs = 0;
        $spaces = 0;
        $spaceWidth = 4;

        for ($i = $blockStart; $i <= $blockEnd && $i < count($lines); $i++) {
            $line = $lines[$i];
            if (preg_match('/^(\t+)\S/', $line)) {
                $tabs++;
            } elseif (preg_match('/^( +)\S/', $line, $m)) {
                $spaces++;
                $spaceWidth = strlen($m[1]);
            }
        }

        if ($tabs > $spaces) {
            return "\t";
        }
        return str_repeat(' ', $spaceWidth);
    }

    /**
     * Build a define block string.
     */
    private function buildBlock(string $type, array $directives, string $indent): string
    {
        $block = "define $type {\n";

        foreach ($directives as $key => $value) {
            if ($key === '' || $value === null) continue;
            $block .= $indent . $key . "\t" . $value . "\n";
        }

        $block .= "}\n";
        return $block;
    }
}
