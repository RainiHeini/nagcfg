<?php

class NagiosParser
{
    private array $config;
    private array $objects = [];
    private array $files = [];
    private array $errors = [];

    private const VALID_TYPES = [
        'host', 'hostgroup', 'hostdependency', 'hostescalation',
        'service', 'servicegroup', 'servicedependency', 'serviceescalation',
        'contact', 'contactgroup',
        'timeperiod',
        'command',
    ];

    private const KEY_MAP = [
        'host'         => 'host_name',
        'hostgroup'    => 'hostgroup_name',
        'servicegroup' => 'servicegroup_name',
        'contact'      => 'contact_name',
        'contactgroup' => 'contactgroup_name',
        'command'      => 'command_name',
        'timeperiod'   => 'timeperiod_name',
    ];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function parse(): void
    {
        $this->objects = [];
        $this->files = [];
        $this->errors = [];

        $this->files = $this->collectFiles();
        foreach ($this->files as $file) {
            $this->parseFile($file);
        }
    }

    /**
     * Read nagios.cfg and collect all cfg_file / cfg_dir entries.
     */
    private function collectFiles(): array
    {
        $nagiosCfg = $this->config['nagios_cfg'];

        if (!is_readable($nagiosCfg)) {
            $this->errors[] = "nagios.cfg not readable: $nagiosCfg";
            return [];
        }

        $lines = file($nagiosCfg, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            $this->errors[] = "Could not read nagios.cfg: $nagiosCfg";
            return [];
        }

        $files = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            if (preg_match('/^cfg_file\s*=\s*(.+)$/', $line, $m)) {
                $path = trim($m[1]);
                if (is_file($path)) {
                    $files[] = $path;
                } else {
                    $this->errors[] = "cfg_file not found: $path";
                }
            } elseif (preg_match('/^cfg_dir\s*=\s*(.+)$/', $line, $m)) {
                $dir = trim($m[1]);
                if (is_dir($dir)) {
                    $found = glob($dir . '/*.cfg');
                    if ($found !== false) {
                        foreach ($found as $f) {
                            if (is_file($f)) {
                                $files[] = $f;
                            }
                        }
                    }
                } else {
                    $this->errors[] = "cfg_dir not found: $dir";
                }
            }
        }

        return array_unique($files);
    }

    /**
     * Parse a single .cfg file and add objects to $this->objects.
     */
    private function parseFile(string $filepath): void
    {
        if (!is_readable($filepath)) {
            $this->errors[] = "File not readable: $filepath";
            return;
        }

        $lines = file($filepath);
        if ($lines === false) {
            $this->errors[] = "Could not read file: $filepath";
            return;
        }

        $inBlock = false;
        $blockType = '';
        $blockStart = 0;
        $blockLines = [];
        $directives = [];
        $commentBuffer = [];

        foreach ($lines as $lineIndex => $line) {
            $lineNum = $lineIndex + 1;
            $trimmed = trim($line);

            if (!$inBlock) {
                // Empty line resets comment buffer
                if ($trimmed === '') {
                    $commentBuffer = [];
                    continue;
                }

                // Comment line — accumulate
                if (isset($trimmed[0]) && ($trimmed[0] === '#' || $trimmed[0] === ';')) {
                    $commentBuffer[] = $line;
                    continue;
                }

                // Start of define block
                if (preg_match('/^\s*define\s+(\w+)\s*\{/', $line, $m)) {
                    $type = strtolower($m[1]);
                    if (in_array($type, self::VALID_TYPES, true)) {
                        $inBlock = true;
                        $blockType = $type;
                        $blockStart = $lineNum;
                        $blockLines = [$line];
                        $directives = [];
                    } else {
                        $this->errors[] = "$filepath:$lineNum: Unknown object type '$type'";
                        $commentBuffer = [];
                    }
                    continue;
                }

                // Any other line outside a block resets comment buffer
                $commentBuffer = [];
            } else {
                // Inside a define block
                $blockLines[] = $line;

                // End of block
                if (preg_match('/^\s*\}/', $trimmed)) {
                    $raw = implode('', $blockLines);
                    $this->objects[] = [
                        'type'       => $blockType,
                        'file'       => $filepath,
                        'line_start' => $blockStart,
                        'line_end'   => $lineNum,
                        'raw'        => $raw,
                        'comments'   => $commentBuffer ? implode('', $commentBuffer) : '',
                        'directives' => $directives,
                    ];
                    $inBlock = false;
                    $commentBuffer = [];
                    continue;
                }

                // Skip empty lines and comments inside blocks (preserved in raw)
                if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
                    continue;
                }

                // Parse directive: key<whitespace>value
                if (preg_match('/^\s*(\S+)\s+(.+)$/', $line, $m)) {
                    $key = $m[1];
                    $value = rtrim($m[2]);

                    // Strip inline comments (; preceded by whitespace) — except for command_line
                    if ($key !== 'command_line') {
                        if (preg_match('/^(.*?)\s+;.*$/', $value, $cm)) {
                            $value = rtrim($cm[1]);
                        }
                    }

                    $directives[$key] = $value;
                } elseif (preg_match('/^\s*(\S+)\s*$/', $line, $m)) {
                    // Directive without value (rare, but possible)
                    $directives[$m[1]] = '';
                }
            }
        }

        // Unclosed block
        if ($inBlock) {
            $this->errors[] = "$filepath:$blockStart: Nicht geschlossener define-Block '$blockType'";
        }
    }

    // ── Getters ──────────────────────────────────────────────────────────

    public function getObjects(): array
    {
        return $this->objects;
    }

    /**
     * Get objects by type.
     * @param bool|null $templatesOnly  null=all, true=templates only, false=non-templates only
     */
    public function getObjectsByType(string $type, ?bool $templatesOnly = null): array
    {
        $result = [];
        foreach ($this->objects as $obj) {
            if ($obj['type'] !== $type) {
                continue;
            }
            if ($templatesOnly !== null) {
                $isTemplate = $this->isTemplate($obj);
                if ($templatesOnly && !$isTemplate) continue;
                if (!$templatesOnly && $isTemplate) continue;
            }
            $result[] = $obj;
        }
        return $result;
    }

    /**
     * Find a specific object by its natural key.
     */
    public function findObject(string $type, string $name, ?string $name2 = null): ?array
    {
        foreach ($this->objects as $obj) {
            if ($obj['type'] !== $type) continue;

            if ($type === 'service') {
                if ($name2 !== null) {
                    $hostMatch = ($obj['directives']['host_name'] ?? '') === $name;
                    $descMatch = ($obj['directives']['service_description'] ?? '') === $name2;
                    if ($hostMatch && $descMatch) return $obj;
                } else {
                    // Search by template name
                    if (($obj['directives']['name'] ?? '') === $name) return $obj;
                }
            } else {
                $keyField = self::KEY_MAP[$type] ?? null;
                if ($keyField && ($obj['directives'][$keyField] ?? '') === $name) {
                    return $obj;
                }
                // Also check template name
                if (($obj['directives']['name'] ?? '') === $name) {
                    return $obj;
                }
            }
        }
        return null;
    }

    /**
     * Find an object by file and line number (for types without natural keys).
     */
    public function findObjectByLocation(string $file, int $line): ?array
    {
        foreach ($this->objects as $obj) {
            if ($obj['file'] === $file && $obj['line_start'] === $line) {
                return $obj;
            }
        }
        return null;
    }

    /**
     * Check if an object is a template (has register 0).
     */
    public function isTemplate(array $obj): bool
    {
        return isset($obj['directives']['register'])
            && $obj['directives']['register'] === '0';
    }

    /**
     * Get the display name for an object.
     */
    public function getDisplayName(array $obj): string
    {
        // Templates use the 'name' directive
        if ($this->isTemplate($obj)) {
            return $obj['directives']['name'] ?? '(unbenanntes Template)';
        }

        $type = $obj['type'];

        if ($type === 'service') {
            $host = $obj['directives']['host_name'] ?? $obj['directives']['hostgroup_name'] ?? '?';
            $desc = $obj['directives']['service_description'] ?? '?';
            return "$host / $desc";
        }

        $keyField = self::KEY_MAP[$type] ?? null;
        if ($keyField && isset($obj['directives'][$keyField])) {
            return $obj['directives'][$keyField];
        }

        // Fallback for types without natural key
        return basename($obj['file']) . ':' . $obj['line_start'];
    }

    /**
     * Get the natural key field name for a type.
     */
    public static function getKeyField(string $type): ?string
    {
        return self::KEY_MAP[$type] ?? null;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get all identifier names for a given type (for datalists/dropdowns).
     * Returns non-template names by default, or template names if $templates=true.
     */
    public function getAllNames(string $type, bool $templates = false): array
    {
        $names = [];
        $keyField = self::KEY_MAP[$type] ?? null;

        foreach ($this->objects as $obj) {
            if ($obj['type'] !== $type) continue;
            $isTemplate = $this->isTemplate($obj);

            if ($templates) {
                if (!$isTemplate) continue;
                $n = $obj['directives']['name'] ?? null;
                if ($n) $names[] = $n;
            } else {
                if ($isTemplate) continue;
                if ($keyField && isset($obj['directives'][$keyField])) {
                    $names[] = $obj['directives'][$keyField];
                }
            }
        }

        $names = array_unique($names);
        sort($names);
        return $names;
    }

    /**
     * Get the list of valid object types.
     */
    public static function getValidTypes(): array
    {
        return self::VALID_TYPES;
    }
}
