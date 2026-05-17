<?php
session_start();
clearstatcache();

$config = require __DIR__ . '/config.php';
if (!empty($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}
require __DIR__ . '/NagiosParser.php';
require __DIR__ . '/NagiosWriter.php';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── POST Handling ───────────────────────────────────────────────────────
$flashMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (($_POST['csrf_token'] ?? '') !== $_SESSION['csrf_token']) {
        $flashMessage = ['type' => 'error', 'text' => 'Invalid CSRF token. Please reload the page.'];
    } else {
        $writer = new NagiosWriter($config);
        $writer->setTransactionId(bin2hex(random_bytes(4)));
        $action = $_GET['action'] ?? $_POST['action'] ?? '';

        if ($action === 'save' || $action === 'save_validate') {
            // Collect directives from form
            $keys = $_POST['keys'] ?? [];
            $values = $_POST['values'] ?? [];
            $directives = [];
            for ($i = 0; $i < count($keys); $i++) {
                $k = trim($keys[$i] ?? '');
                $v = $values[$i] ?? '';
                if ($k !== '') {
                    $directives[$k] = $v;
                }
            }

            $file = $_POST['file'] ?? '';
            $lineStart = (int) ($_POST['line_start'] ?? 0);
            $lineEnd = (int) ($_POST['line_end'] ?? 0);
            $postType = $_POST['type'] ?? '';
            $expectedMtime = (int) ($_POST['filemtime'] ?? 0);

            // Path validation: file must be in the known config files
            $parser_tmp = new NagiosParser($config);
            $parser_tmp->parse();
            $allowedFiles = $parser_tmp->getFiles();
            if (!in_array($file, $allowedFiles, true)) {
                $flashMessage = ['type' => 'error', 'text' => 'File path not allowed.'];
            } else {
                // Check for duplicate name before saving
                $oldKey = $_POST['old_key'] ?? null;
                $keyField = NagiosParser::getKeyField($postType);
                if ($oldKey !== null && $keyField) {
                    $newKey = $directives[$keyField] ?? null;
                    if ($newKey !== null && $newKey !== $oldKey) {
                        $existing = $parser_tmp->findObject($postType, $newKey);
                        if ($existing) {
                            $flashMessage = ['type' => 'error', 'text' => "Name '$newKey' already exists. Rename aborted."];
                        }
                    }
                }
                if ($flashMessage && $flashMessage['type'] === 'error') {
                    // Skip save
                } else {
                $result = $writer->saveObject($file, $lineStart, $lineEnd, $postType, $directives, $expectedMtime);
                if ($result['success']) {
                    $flashMessage = ['type' => 'success', 'text' => 'Changes saved.'];

                    // Cascade rename if primary key changed
                    $oldKey = $_POST['old_key'] ?? null;
                    $oldKey2 = $_POST['old_key2'] ?? null;
                    $oldTplName = $_POST['old_tpl_name'] ?? null;
                    $keyField = NagiosParser::getKeyField($postType);

                    if ($oldKey !== null && $keyField) {
                        $newKey = $directives[$keyField] ?? null;
                        if ($newKey !== null && $newKey !== $oldKey) {
                            $cascaded = cascadeRename($config, $writer, $postType, $oldKey, $newKey);
                            if ($cascaded > 0) {
                                $flashMessage['text'] .= " $cascaded reference" . ($cascaded > 1 ? 's' : '') . " updated.";
                            }
                        }
                    }

                    // Cascade template name rename (use directive in other objects)
                    if ($oldTplName !== null) {
                        $newTplName = $directives['name'] ?? null;
                        if ($newTplName !== null && $newTplName !== $oldTplName) {
                            // Update 'use' directive in all objects of this type
                            $parser_tmp2 = new NagiosParser($config);
                            $parser_tmp2->parse();
                            $tplRenamed = 0;
                            $toFix = [];
                            foreach ($parser_tmp2->getObjectsByType($postType) as $obj2) {
                                $uses = $obj2['directives']['use'] ?? '';
                                if ($uses === '') continue;
                                $parts = array_map('trim', explode(',', $uses));
                                if (in_array($oldTplName, $parts, true)) {
                                    $toFix[] = $obj2['file'] . ':' . $obj2['line_start'];
                                }
                            }
                            foreach ($toFix as $loc) {
                                $parser_tmp2 = new NagiosParser($config);
                                $parser_tmp2->parse();
                                [$f, $l] = explode(':', $loc);
                                $obj2 = $parser_tmp2->findObjectByLocation($f, (int)$l);
                                if (!$obj2) continue;
                                $parts = array_map('trim', explode(',', $obj2['directives']['use']));
                                $parts = array_map(fn($p) => $p === $oldTplName ? $newTplName : $p, $parts);
                                $dirs2 = $obj2['directives'];
                                $dirs2['use'] = implode(',', $parts);
                                clearstatcache(true, $obj2['file']);
                                $wr = $writer->saveObject($obj2['file'], $obj2['line_start'], $obj2['line_end'], $obj2['type'], $dirs2, filemtime($obj2['file']));
                                if ($wr['success']) $tplRenamed++;
                            }
                            if ($tplRenamed > 0) {
                                $flashMessage['text'] .= " $tplRenamed use reference" . ($tplRenamed > 1 ? 's' : '') . " updated.";
                            }
                        }
                    }

                    // Validate and auto-reload Nagios
                    $output = [];
                    $returnCode = 0;
                    exec($config['nagios_bin'] . ' -v ' . escapeshellarg($config['nagios_cfg']) . ' 2>&1', $output, $returnCode);
                    if ($returnCode === 0) {
                        $reload = $writer->reloadNagios();
                        if ($reload['success']) {
                            $flashMessage['text'] .= ' Nagios reloaded.';
                        }
                    } else {
                        $flashMessage['text'] .= ' Warning: Nagios was NOT reloaded (validation error).';
                        $flashMessage['type'] = 'warning';
                        $flashMessage['details'] = implode("\n", $output);
                    }

                    // PRG redirect back to edit view
                    $_SESSION['flash'] = $flashMessage;
                    $keyChanged = false;
                    if ($oldKey !== null && $keyField) {
                        $newKey = $directives[$keyField] ?? null;
                        if ($newKey !== null && $newKey !== $oldKey) $keyChanged = true;
                    }
                    if ($oldTplName !== null && ($directives['name'] ?? null) !== null && ($directives['name'] ?? '') !== $oldTplName) {
                        $keyChanged = true;
                    }
                    if ($keyChanged) {
                        if ($postType === 'service' && isset($directives['host_name'], $directives['service_description'])) {
                            header('Location: /nagcfg/?view=edit&type=service&host=' . urlencode($directives['host_name']) . '&desc=' . urlencode($directives['service_description']));
                        } elseif ($keyField && isset($directives[$keyField])) {
                            header('Location: /nagcfg/?view=edit&type=' . urlencode($postType) . '&name=' . urlencode($directives[$keyField]));
                        } elseif (isset($directives['name'])) {
                            header('Location: /nagcfg/?view=edit&type=' . urlencode($postType) . '&name=' . urlencode($directives['name']));
                        }
                    } else {
                        if ($postType === 'service' && isset($directives['host_name'], $directives['service_description'])) {
                            header('Location: /nagcfg/?view=edit&type=service&host=' . urlencode($directives['host_name']) . '&desc=' . urlencode($directives['service_description']));
                        } elseif ($keyField && isset($directives[$keyField])) {
                            header('Location: /nagcfg/?view=edit&type=' . urlencode($postType) . '&name=' . urlencode($directives[$keyField]));
                        } elseif (isset($directives['name'])) {
                            header('Location: /nagcfg/?view=edit&type=' . urlencode($postType) . '&name=' . urlencode($directives['name']));
                        }
                    }
                    exit;
                } else {
                    $flashMessage = ['type' => 'error', 'text' => $result['message']];
                }
                } // end duplicate check else
            }

        } elseif ($action === 'create') {
            $keys = $_POST['keys'] ?? [];
            $values = $_POST['values'] ?? [];
            $directives = [];
            for ($i = 0; $i < count($keys); $i++) {
                $k = trim($keys[$i] ?? '');
                $v = $values[$i] ?? '';
                if ($k !== '') {
                    $directives[$k] = $v;
                }
            }

            $targetFile = $_POST['target_file'] ?? '';
            $postType = $_POST['type'] ?? '';

            // Path validation
            $parser_tmp = new NagiosParser($config);
            $parser_tmp->parse();
            $allowedFiles = $parser_tmp->getFiles();
            $allowedDirs = [];
            // Also allow new files in known cfg_dirs
            $nagiosCfgLines = file($config['nagios_cfg'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($nagiosCfgLines as $cfgLine) {
                if (preg_match('/^cfg_dir\s*=\s*(.+)$/', trim($cfgLine), $m)) {
                    $allowedDirs[] = rtrim(trim($m[1]), '/');
                }
            }
            // Also allow directories of existing cfg_file entries
            foreach ($allowedFiles as $af) {
                $allowedDirs[] = dirname($af);
            }
            $allowedDirs = array_unique($allowedDirs);

            $fileAllowed = in_array($targetFile, $allowedFiles, true);
            if (!$fileAllowed) {
                // Check if it's a new file in an allowed directory
                $targetDir = dirname($targetFile);
                $fileAllowed = in_array($targetDir, $allowedDirs, true)
                    && str_ends_with($targetFile, '.cfg')
                    && !str_contains(basename($targetFile), '..');
            }

            if (!$fileAllowed) {
                $flashMessage = ['type' => 'error', 'text' => 'Target file not allowed: ' . $targetFile];
            } elseif (empty($directives)) {
                $flashMessage = ['type' => 'error', 'text' => 'No directives specified.'];
            } else {
                $result = $writer->appendObject($targetFile, $postType, $directives);
                if ($result['success']) {
                    $flashMessage = ['type' => 'success', 'text' => 'Object created.'];
                    // Redirect to edit view
                    $keyField = NagiosParser::getKeyField($postType);
                    if ($postType === 'service' && isset($directives['host_name'], $directives['service_description'])) {
                        header('Location: /nagcfg/?view=edit&type=service&host=' . urlencode($directives['host_name']) . '&desc=' . urlencode($directives['service_description']) . '&saved=1');
                        exit;
                    } elseif ($keyField && isset($directives[$keyField])) {
                        header('Location: /nagcfg/?view=edit&type=' . urlencode($postType) . '&name=' . urlencode($directives[$keyField]) . '&saved=1');
                        exit;
                    } elseif (isset($directives['name'])) {
                        header('Location: /nagcfg/?view=edit&type=' . urlencode($postType) . '&name=' . urlencode($directives['name']) . '&saved=1');
                        exit;
                    }
                } else {
                    $flashMessage = ['type' => 'error', 'text' => $result['message']];
                }
            }

        } elseif ($action === 'delete') {
            $file = $_POST['file'] ?? '';
            $lineStart = (int) ($_POST['line_start'] ?? 0);
            $lineEnd = (int) ($_POST['line_end'] ?? 0);
            $expectedMtime = (int) ($_POST['filemtime'] ?? 0);
            $postType = $_POST['type'] ?? '';
            $comments = $_POST['comments'] ?? '';
            $confirmName = $_POST['confirm_name'] ?? '';
            $expectedName = $_POST['expected_name'] ?? '';

            if ($confirmName !== $expectedName) {
                $flashMessage = ['type' => 'error', 'text' => 'Confirmation name does not match.'];
            } else {
                $parser_tmp = new NagiosParser($config);
                $parser_tmp->parse();
                if (!in_array($file, $parser_tmp->getFiles(), true)) {
                    $flashMessage = ['type' => 'error', 'text' => 'File path not allowed.'];
                } else {
                    $result = $writer->deleteObject($file, $lineStart, $lineEnd, $expectedMtime, $comments);
                    if ($result['success']) {
                        $flashMessage = ['type' => 'success', 'text' => 'Object deleted.'];

                        // Cascade delete: remove dependent objects and clean up references
                        $deletedName = $expectedName;
                        if ($deletedName !== '') {
                            $cascade = cascadeDelete($config, $writer, $postType, $deletedName);
                            if ($cascade['deleted'] > 0) {
                                $flashMessage['text'] .= ' ' . $cascade['deleted'] . ' dependent object' . ($cascade['deleted'] > 1 ? 's' : '') . ' removed.';
                            }
                            if ($cascade['cleaned'] > 0) {
                                $flashMessage['text'] .= ' ' . $cascade['cleaned'] . ' reference' . ($cascade['cleaned'] > 1 ? 's' : '') . ' cleaned.';
                            }
                        }

                        // Validate and auto-reload Nagios
                        $output = [];
                        $returnCode = 0;
                        exec($config['nagios_bin'] . ' -v ' . escapeshellarg($config['nagios_cfg']) . ' 2>&1', $output, $returnCode);
                        if ($returnCode === 0) {
                            $reload = $writer->reloadNagios();
                            if ($reload['success']) {
                                $flashMessage['text'] .= ' Nagios reloaded.';
                            }
                        } else {
                            $flashMessage['text'] .= ' Warning: Nagios was NOT reloaded (validation error).';
                            $flashMessage['type'] = 'warning';
                            $flashMessage['details'] = implode("\n", $output);
                        }

                        $_SESSION['flash'] = $flashMessage;
                        header('Location: /nagcfg/?view=list&type=' . urlencode($postType));
                        exit;
                    } else {
                        $flashMessage = ['type' => 'error', 'text' => $result['message']];
                    }
                }
            }

        } elseif ($action === 'save_raw') {
            $file = $_POST['file'] ?? '';
            $content = $_POST['content'] ?? '';
            $expectedMtime = (int) ($_POST['filemtime'] ?? 0);

            $parser_tmp = new NagiosParser($config);
            $parser_tmp->parse();
            if (!in_array($file, $parser_tmp->getFiles(), true)) {
                $flashMessage = ['type' => 'error', 'text' => 'File path not allowed.'];
            } else {
                $result = $writer->saveRaw($file, $content, $expectedMtime);
                $flashMessage = $result['success']
                    ? ['type' => 'success', 'text' => 'File saved.']
                    : ['type' => 'error', 'text' => $result['message']];
            }

        } elseif ($action === 'reload') {
            $result = $writer->reloadNagios();
            $flashMessage = $result['success']
                ? ['type' => 'success', 'text' => $result['message']]
                : ['type' => 'error', 'text' => $result['message']];

        } elseif ($action === 'actionurl') {
            $step = $_POST['step'] ?? '';

            if ($step === 'install') {
                // Install both nagcfg-host and nagcfg-service, activate on all templates
                $parser_tmp = new NagiosParser($config);
                $parser_tmp->parse();
                $msgs = [];
                $ok = true;

                foreach (['host' => 'nagcfg-host', 'service' => 'nagcfg-service'] as $tplType => $nagcfgName) {
                    // Skip if already exists
                    if ($parser_tmp->findObject($tplType, $nagcfgName)) {
                        continue;
                    }
                    // Find target file
                    $tplFile = null;
                    foreach ($parser_tmp->getObjectsByType($tplType, true) as $t) {
                        $tplFile = $t['file'];
                        break;
                    }
                    if (!$tplFile) {
                        $files = $parser_tmp->getFiles();
                        $tplFile = $files[0] ?? null;
                    }
                    if (!$tplFile) {
                        $msgs[] = "No config file found for $tplType.";
                        $ok = false;
                        continue;
                    }
                    $directives = ['name' => $nagcfgName, 'register' => '0'];
                    if ($tplType === 'host') {
                        $directives['action_url'] = '/nagcfg/?view=edit&type=host&name=$HOSTNAME$';
                    } else {
                        $directives['action_url'] = '/nagcfg/?view=edit&type=service&host=$HOSTNAME$&desc=$SERVICEDESC$';
                    }
                    $result = $writer->appendObject($tplFile, $tplType, $directives);
                    if ($result['success']) {
                        $activated = activateNagcfgAll($config, $writer, $tplType, $nagcfgName);
                        $m = "'$nagcfgName' created";
                        if ($activated > 0) {
                            $m .= ", $activated template" . ($activated > 1 ? 's' : '') . " activated";
                        }
                        $msgs[] = $m;
                    } else {
                        $msgs[] = "$nagcfgName: " . $result['message'];
                        $ok = false;
                    }
                    // Re-parse for the next iteration
                    $parser_tmp = new NagiosParser($config);
                    $parser_tmp->parse();
                }

                // Install gear icon (always, even if templates already exist)
                $iconErr = installActionIcon($config);
                if ($iconErr) $msgs[] = $iconErr;

                if (empty($msgs)) {
                    $flashMessage = ['type' => 'success', 'text' => 'Already installed.'];
                } else {
                    $flashMessage = ['type' => $ok ? 'success' : 'error', 'text' => implode('. ', $msgs) . '.'];
                }

            } elseif ($step === 'uninstall') {
                // Uninstall both nagcfg-host and nagcfg-service
                $msgs = [];
                $ok = true;

                foreach (['host' => 'nagcfg-host', 'service' => 'nagcfg-service'] as $tplType => $nagcfgName) {
                    $parser_tmp = new NagiosParser($config);
                    $parser_tmp->parse();

                    // Skip if doesn't exist
                    if (!$parser_tmp->findObject($tplType, $nagcfgName)) {
                        continue;
                    }

                    // Collect templates that reference nagcfg directly
                    $allTpls = $parser_tmp->getObjectsByType($tplType, true);
                    $toClean = [];
                    foreach ($allTpls as $t) {
                        $tName = $t['directives']['name'] ?? '';
                        if ($tName === '' || $tName === $nagcfgName) continue;
                        $uses = array_map('trim', explode(',', $t['directives']['use'] ?? ''));
                        if (in_array($nagcfgName, $uses, true)) {
                            $toClean[] = $tName;
                        }
                    }

                    // Remove nagcfg from each, re-parsing between saves
                    foreach ($toClean as $tName) {
                        $parser_tmp = new NagiosParser($config);
                        $parser_tmp->parse();
                        $t = $parser_tmp->findObject($tplType, $tName);
                        if (!$t) continue;
                        $uses = array_map('trim', explode(',', $t['directives']['use'] ?? ''));
                        $uses = array_filter($uses, fn($u) => $u !== $nagcfgName);
                        $dirs = $t['directives'];
                        if (empty($uses)) {
                            unset($dirs['use']);
                        } else {
                            $dirs['use'] = implode(',', $uses);
                        }
                        clearstatcache(true, $t['file']);
                        $writer->saveObject($t['file'], $t['line_start'], $t['line_end'], $tplType, $dirs, filemtime($t['file']));
                    }

                    // Re-parse to get fresh line numbers before deleting the template
                    $parser_tmp = new NagiosParser($config);
                    $parser_tmp->parse();
                    $tpl = $parser_tmp->findObject($tplType, $nagcfgName);
                    if (!$tpl) {
                        $msgs[] = "'$nagcfgName' not found";
                        $ok = false;
                    } else {
                        clearstatcache(true, $tpl['file']);
                        $result = $writer->deleteObject($tpl['file'], $tpl['line_start'], $tpl['line_end'], filemtime($tpl['file']), $tpl['comments']);
                        if ($result['success']) {
                            $msgs[] = "'$nagcfgName' removed";
                        } else {
                            $msgs[] = "$nagcfgName: " . $result['message'];
                            $ok = false;
                        }
                    }
                }

                // Restore original action icon
                if ($ok) {
                    $iconErr = uninstallActionIcon($config);
                    if ($iconErr) $msgs[] = $iconErr;
                }

                if (empty($msgs)) {
                    $flashMessage = ['type' => 'success', 'text' => 'Already uninstalled.'];
                } else {
                    $flashMessage = ['type' => $ok ? 'success' : 'error', 'text' => implode('. ', $msgs) . '.'];
                }

            } elseif ($step === 'activate_all') {
                $msgs = [];
                foreach (['host' => 'nagcfg-host', 'service' => 'nagcfg-service'] as $tplType => $nagcfgName) {
                    $activated = activateNagcfgAll($config, $writer, $tplType, $nagcfgName);
                    if ($activated > 0) {
                        $msgs[] = "$nagcfgName: $activated template" . ($activated > 1 ? 's' : '') . " activated";
                    }
                }
                if (empty($msgs)) {
                    $flashMessage = ['type' => 'success', 'text' => 'Already active everywhere.'];
                } else {
                    $flashMessage = ['type' => 'success', 'text' => implode('. ', $msgs) . '.'];
                }
            }

            // Auto-reload Nagios after successful config change (only if config validates)
            if ($flashMessage && $flashMessage['type'] === 'success') {
                $validateOut = [];
                $validateRc = 0;
                exec($config['nagios_bin'] . ' -v ' . escapeshellarg($config['nagios_cfg']) . ' 2>&1', $validateOut, $validateRc);
                if ($validateRc === 0) {
                    $reload = $writer->reloadNagios();
                    if ($reload['success']) {
                        $flashMessage['text'] .= ' Nagios reloaded.';
                    }
                } else {
                    $flashMessage['text'] .= ' Warning: Nagios was NOT reloaded (validation error). Please check configuration.';
                    $flashMessage['type'] = 'error';
                }
            }
        }
    }

    // ── Restore Backup ──────────────────────────────────────────────────
    if ($action === 'restore') {
        $backupFiles = $_POST['backup_files'] ?? [];
        if (!is_array($backupFiles) || empty($backupFiles)) {
            $flashMessage = ['type' => 'error', 'text' => 'No backup files specified.'];
        } else {
            $backupDir = rtrim($config['backup_dir'], '/');
            $realBackupDir = realpath($backupDir);
            $parser_tmp = new NagiosParser($config);
            $parser_tmp->parse();
            $knownFiles = $parser_tmp->getFiles();

            $restored = [];
            $errors = [];

            foreach ($backupFiles as $backupFile) {
                $realBackup = realpath($backupFile);
                if ($realBackup === false || $realBackupDir === false || !str_starts_with($realBackup, $realBackupDir . '/')) {
                    $errors[] = 'Invalid path: ' . basename($backupFile);
                    continue;
                }
                if (!is_file($realBackup)) {
                    $errors[] = 'Not found: ' . basename($backupFile);
                    continue;
                }
                $basename = basename($realBackup);
                // Match both old and new format
                if (!preg_match('/^(.+?)_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}(_[a-f0-9]+)?\.bak$/', $basename, $m)) {
                    $errors[] = 'Invalid format: ' . $basename;
                    continue;
                }
                $originalName = $m[1];
                $targetFile = null;
                foreach ($knownFiles as $f) {
                    if (basename($f) === $originalName) {
                        $targetFile = $f;
                        break;
                    }
                }
                if (!$targetFile) {
                    $errors[] = "No config file for '$originalName'";
                    continue;
                }
                $writer->backup($targetFile);
                if (@copy($realBackup, $targetFile)) {
                    $restored[] = $originalName;
                } else {
                    $errors[] = "Could not restore $originalName";
                }
            }

            if (!empty($restored)) {
                $flashMessage = ['type' => 'success', 'text' => 'Restored: ' . implode(', ', $restored) . '.'];
                // Validate and reload
                $output = [];
                $returnCode = 0;
                exec($config['nagios_bin'] . ' -v ' . escapeshellarg($config['nagios_cfg']) . ' 2>&1', $output, $returnCode);
                if ($returnCode === 0) {
                    $reload = $writer->reloadNagios();
                    if ($reload['success']) {
                        $flashMessage['text'] .= ' Nagios reloaded.';
                    }
                } else {
                    $flashMessage['text'] .= ' Warning: Nagios was NOT reloaded (validation error).';
                    $flashMessage['type'] = 'warning';
                    $flashMessage['details'] = implode("\n", $output);
                }
            }
            if (!empty($errors)) {
                $errText = implode('; ', $errors);
                if ($flashMessage) {
                    $flashMessage['text'] .= " Errors: $errText";
                    $flashMessage['type'] = 'error';
                } else {
                    $flashMessage = ['type' => 'error', 'text' => $errText];
                }
            }
        }

        $_SESSION['flash'] = $flashMessage;
        header('Location: /nagcfg/?view=backups');
        exit;
    }

    // Regenerate CSRF token after POST
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    // PRG redirect for actionurl actions (stay on settings page)
    if ($action === 'actionurl' && $flashMessage) {
        $_SESSION['flash'] = $flashMessage;
        header('Location: /nagcfg/?view=settings#actionurl');
        exit;
    }

    // PRG redirect for reload (stay on current page)
    if ($action === 'reload' && $flashMessage) {
        $_SESSION['flash'] = $flashMessage;
        $referer = $_SERVER['HTTP_REFERER'] ?? '/nagcfg/';
        header('Location: ' . $referer);
        exit;
    }
}

// Restore flash from session (after PRG redirect)
if (isset($_SESSION['flash'])) {
    $flashMessage = $flashMessage ?? $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// Redirect flash from GET params
if (isset($_GET['saved']))   $flashMessage = $flashMessage ?? ['type' => 'success', 'text' => 'Changes saved.'];
if (isset($_GET['deleted'])) $flashMessage = $flashMessage ?? ['type' => 'success', 'text' => 'Object deleted.'];

// Parse all config files (re-parse after potential writes)
$parser = new NagiosParser($config);
$parser->parse();

// Routing
$view = $_GET['view'] ?? 'dashboard';
$type = $_GET['type'] ?? '';

// ── Helper ──────────────────────────────────────────────────────────────

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function activeClass(string $current, string $check): string
{
    return $current === $check ? ' class="active"' : '';
}

/**
 * Directive → referenced object type mapping.
 * Values starting with __ are special: __templates__ = same-type templates.
 */
function refMap(string $directive, string $objectType): ?string
{
    // Key fields are not references in their own type
    $keyFields = [
        'host' => 'host_name', 'hostgroup' => 'hostgroup_name',
        'service' => 'service_description', 'servicegroup' => 'servicegroup_name',
        'contact' => 'contact_name', 'contactgroup' => 'contactgroup_name',
        'command' => 'command_name', 'timeperiod' => 'timeperiod_name',
    ];
    if (isset($keyFields[$objectType]) && $keyFields[$objectType] === $directive) return null;

    static $map = [
        'use'                          => '__templates__',
        'check_command'                => 'command',
        'event_handler'                => 'command',
        'host_notification_commands'   => 'command',
        'service_notification_commands'=> 'command',
        'check_period'                 => 'timeperiod',
        'notification_period'          => 'timeperiod',
        'host_notification_period'     => 'timeperiod',
        'service_notification_period'  => 'timeperiod',
        'dependency_period'            => 'timeperiod',
        'escalation_period'            => 'timeperiod',
        'contacts'                     => 'contact',
        'contact_groups'               => 'contactgroup',
        'contactgroups'                => 'contactgroup',
        'hostgroups'                   => 'hostgroup',
        'servicegroups'                => 'servicegroup',
        'host_name'                    => 'host',
        'hostgroup_name'               => 'hostgroup',
        'parents'                      => 'host',
        'dependent_host_name'          => 'host',
        'dependent_hostgroup_name'     => 'hostgroup',
        'hostgroup_members'            => 'hostgroup',
        'contactgroup_members'         => 'contactgroup',
        'servicegroup_members'         => 'servicegroup',
        'exclude'                      => 'timeperiod',
        'servicegroup_name'            => 'servicegroup',
        'dependent_servicegroup_name'  => 'servicegroup',
    ];

    // 'members' depends on containing object type
    if ($directive === 'members') {
        return match ($objectType) {
            'hostgroup' => 'host',
            'contactgroup' => 'contact',
            default => null,
        };
    }

    return $map[$directive] ?? null;
}

/**
 * Known directives per object type (for autocomplete).
 */
function directivesForType(string $type): array
{
    static $map = [
        'host' => [
            'host_name','alias','display_name','address','parents','hostgroups',
            'check_command','initial_state','max_check_attempts','check_interval',
            'retry_interval','active_checks_enabled','passive_checks_enabled',
            'check_period','obsess_over_host','check_freshness','freshness_threshold',
            'event_handler','event_handler_enabled','low_flap_threshold',
            'high_flap_threshold','flap_detection_enabled','flap_detection_options',
            'process_perf_data','retain_status_information','retain_nonstatus_information',
            'contacts','contact_groups','notification_interval','first_notification_delay',
            'notification_period','notification_options','notifications_enabled',
            'stalking_options','notes','notes_url','action_url','icon_image',
            'icon_image_alt','vrml_image','statusmap_image','2d_coords','3d_coords',
            'name','use','register',
        ],
        'service' => [
            'host_name','hostgroup_name','service_description','display_name',
            'servicegroups','is_volatile','check_command','initial_state',
            'max_check_attempts','check_interval','retry_interval',
            'active_checks_enabled','passive_checks_enabled','check_period',
            'obsess_over_service','check_freshness','freshness_threshold',
            'event_handler','event_handler_enabled','low_flap_threshold',
            'high_flap_threshold','flap_detection_enabled','flap_detection_options',
            'process_perf_data','retain_status_information','retain_nonstatus_information',
            'notification_interval','first_notification_delay','notification_period',
            'notification_options','notifications_enabled','contacts','contact_groups',
            'stalking_options','notes','notes_url','action_url','icon_image',
            'icon_image_alt','name','use','register',
        ],
        'contact' => [
            'contact_name','alias','contactgroups','host_notifications_enabled',
            'service_notifications_enabled','host_notification_period',
            'service_notification_period','host_notification_options',
            'service_notification_options','host_notification_commands',
            'service_notification_commands','email','pager',
            'address1','address2','address3','address4','address5','address6',
            'can_submit_commands',
            'retain_status_information','retain_nonstatus_information',
            'name','use','register',
        ],
        'contactgroup' => [
            'contactgroup_name','alias','members','contactgroup_members',
            'name','use','register',
        ],
        'hostgroup' => [
            'hostgroup_name','alias','members','hostgroup_members',
            'notes','notes_url','action_url','name','use','register',
        ],
        'servicegroup' => [
            'servicegroup_name','alias','members','servicegroup_members',
            'notes','notes_url','action_url','name','use','register',
        ],
        'command' => ['command_name','command_line'],
        'timeperiod' => [
            'timeperiod_name','alias','sunday','monday','tuesday','wednesday',
            'thursday','friday','saturday','exclude','name','use','register',
        ],
        'hostdependency' => [
            'dependent_host_name','dependent_hostgroup_name','host_name','hostgroup_name',
            'inherits_parent','execution_failure_criteria','notification_failure_criteria',
            'dependency_period','name','use','register',
        ],
        'hostescalation' => [
            'host_name','hostgroup_name','contacts','contact_groups',
            'first_notification','last_notification','notification_interval',
            'escalation_period','escalation_options','name','use','register',
        ],
        'servicedependency' => [
            'dependent_host_name','dependent_hostgroup_name','dependent_service_description',
            'dependent_servicegroup_name','host_name','hostgroup_name','service_description',
            'servicegroup_name','inherits_parent',
            'execution_failure_criteria','notification_failure_criteria',
            'dependency_period','name','use','register',
        ],
        'serviceescalation' => [
            'host_name','hostgroup_name','service_description','contacts','contact_groups',
            'first_notification','last_notification','notification_interval',
            'escalation_period','escalation_options','name','use','register',
        ],
    ];
    return $map[$type] ?? [];
}

/**
 * Columns to show in list view per object type.
 * Each entry: [directive_key, column_label, truncate_length|0]
 */
function listColumns(string $type): array
{
    return match ($type) {
        'host'              => [['host_name','Host',0], ['alias','Alias',0], ['address','Address',0], ['use','Template',0]],
        'hostgroup'         => [['hostgroup_name','Hostgroup',0], ['alias','Alias',0], ['members','Members',60]],
        'service'           => [['host_name','Host',0], ['service_description','Service',0], ['check_command','Check Command',40], ['use','Template',0]],
        'servicegroup'      => [['servicegroup_name','Servicegroup',0], ['alias','Alias',0], ['members','Members',60]],
        'contact'           => [['contact_name','Contact',0], ['alias','Alias',0], ['email','E-Mail',0]],
        'contactgroup'      => [['contactgroup_name','Contactgroup',0], ['alias','Alias',0], ['members','Members',60]],
        'command'           => [['command_name','Command',0], ['command_line','Command Line',80]],
        'timeperiod'        => [['timeperiod_name','Timeperiod',0], ['alias','Alias',0]],
        'hostdependency'    => [['host_name','Host',0], ['dependent_host_name','Dep. Host',0]],
        'hostescalation'    => [['host_name','Host',0], ['contact_groups','Contact Groups',0]],
        'servicedependency' => [['host_name','Host',0], ['service_description','Service',0], ['dependent_host_name','Dep. Host',0]],
        'serviceescalation' => [['host_name','Host',0], ['service_description','Service',0], ['contact_groups','Contact Groups',0]],
        default             => [],
    };
}

/**
 * Human-readable labels for object types.
 */
function typeLabel(string $type): string
{
    return match ($type) {
        'host'              => 'Hosts',
        'hostgroup'         => 'Hostgroups',
        'hostdependency'    => 'Host Dependencies',
        'hostescalation'    => 'Host Escalations',
        'service'           => 'Services',
        'servicegroup'      => 'Servicegroups',
        'servicedependency' => 'Service Dependencies',
        'serviceescalation' => 'Service Escalations',
        'contact'           => 'Contacts',
        'contactgroup'      => 'Contactgroups',
        'timeperiod'        => 'Timeperiods',
        'command'           => 'Commands',
        default             => ucfirst($type),
    };
}

/**
 * Build the edit URL for an object.
 */
function editUrl(array $obj, NagiosParser $parser): string
{
    $type = $obj['type'];

    if ($parser->isTemplate($obj)) {
        $name = $obj['directives']['name'] ?? '';
        return '/nagcfg/?view=edit&type=' . urlencode($type) . '&name=' . urlencode($name);
    }

    if ($type === 'service') {
        $host = $obj['directives']['host_name'] ?? '';
        $desc = $obj['directives']['service_description'] ?? '';
        return '/nagcfg/?view=edit&type=service&host=' . urlencode($host) . '&desc=' . urlencode($desc);
    }

    $keyField = NagiosParser::getKeyField($type);
    if ($keyField && isset($obj['directives'][$keyField])) {
        return '/nagcfg/?view=edit&type=' . urlencode($type) . '&name=' . urlencode($obj['directives'][$keyField]);
    }

    // Fallback: identify by file + line
    return '/nagcfg/?view=edit&type=' . urlencode($type)
         . '&file=' . urlencode($obj['file'])
         . '&line=' . $obj['line_start'];
}

/**
 * Build an edit URL for a referenced object name.
 */
function refEditUrl(string $refType, string $name): string
{
    if ($refType === 'service') {
        return '#'; // services need host+desc, can't link by name alone
    }
    return '/nagcfg/?view=edit&type=' . urlencode($refType) . '&name=' . urlencode($name);
}

/**
 * Build a link for a single referenced value (or plain text if not found).
 */
function refLink(string $name, string $refType): string
{
    $url = refEditUrl($refType, $name);
    return '<a href="' . h($url) . '" class="ref-link">' . h($name) . '</a>';
}

/**
 * Render a cell value with links for referenced objects.
 * Handles comma-separated values and command!args syntax.
 */
function linkedValue(string $value, string $directive, string $objectType, int $truncate = 0): string
{
    $refType = refMap($directive, $objectType);
    if (!$refType || $refType === '__templates__') {
        // For templates, link to the template
        if ($refType === '__templates__') {
            $parts = array_map('trim', explode(',', $value));
            $links = [];
            foreach ($parts as $p) {
                if ($p !== '') {
                    $links[] = '<a href="' . h(refEditUrl($objectType, $p)) . '" class="ref-link">' . h($p) . '</a>';
                }
            }
            return implode(', ', $links);
        }
        $display = $truncate > 0 && mb_strlen($value) > $truncate
            ? mb_substr($value, 0, $truncate) . '...'
            : $value;
        return h($display);
    }

    // For check_command: strip !args for the link
    if ($directive === 'check_command' || $directive === 'event_handler') {
        $cmdParts = explode('!', $value, 2);
        $cmdName = $cmdParts[0];
        $args = isset($cmdParts[1]) ? '!' . $cmdParts[1] : '';
        $display = $truncate > 0 && mb_strlen($value) > $truncate
            ? mb_substr($value, 0, $truncate) . '...'
            : $value;
        return '<a href="' . h(refEditUrl('command', $cmdName)) . '" class="ref-link">' . h($display) . '</a>';
    }

    // Comma-separated references
    $parts = array_map('trim', explode(',', $value));
    if (count($parts) > 1 || $refType !== null) {
        $links = [];
        foreach ($parts as $p) {
            if ($p === '') continue;
            $links[] = '<a href="' . h(refEditUrl($refType, $p)) . '" class="ref-link">' . h($p) . '</a>';
        }
        $result = implode(', ', $links);
        if ($truncate > 0 && mb_strlen($value) > $truncate) {
            // Show truncated plain text instead
            return '<a href="' . h(refEditUrl($refType, $parts[0])) . '" class="ref-link">'
                 . h(mb_substr($value, 0, $truncate)) . '...</a>';
        }
        return $result;
    }

    return h($value);
}

/**
 * Check if a template inherits nagcfg-host/nagcfg-service through its use chain.
 * Returns the template name where it's found, or null.
 */
function inheritsNagcfg(string $templateName, string $nagcfgName, array $allTemplates, int $depth = 0): ?string
{
    if ($depth > 10) return null;
    foreach ($allTemplates as $t) {
        $tName = $t['directives']['name'] ?? '';
        if ($tName !== $templateName) continue;
        $uses = array_map('trim', explode(',', $t['directives']['use'] ?? ''));
        if (in_array($nagcfgName, $uses, true)) return $tName;
        foreach ($uses as $parent) {
            if ($parent === '' || $parent === $nagcfgName) continue;
            $found = inheritsNagcfg($parent, $nagcfgName, $allTemplates, $depth + 1);
            if ($found) return $found;
        }
    }
    return null;
}

/**
 * Reference map: when a key field changes, which directives in which object types need updating?
 * Format: sourceType => [ [targetType, directive, isCommaSeparated], ... ]
 */
function getRenameReferenceMap(): array
{
    return [
        'host' => [  // host_name changed
            ['host', 'parents', true],
            ['service', 'host_name', true],
            ['hostgroup', 'members', true],
            ['servicegroup', 'members', 'svcgroup_members'],
            ['hostdependency', 'host_name', true],
            ['hostdependency', 'dependent_host_name', true],
            ['hostescalation', 'host_name', true],
            ['servicedependency', 'host_name', true],
            ['servicedependency', 'dependent_host_name', true],
            ['serviceescalation', 'host_name', true],
        ],
        'hostgroup' => [  // hostgroup_name changed
            ['host', 'hostgroups', true],
            ['hostgroup', 'hostgroup_members', true],
            ['service', 'hostgroup_name', true],
            ['hostdependency', 'hostgroup_name', true],
            ['hostdependency', 'dependent_hostgroup_name', true],
            ['hostescalation', 'hostgroup_name', true],
            ['servicedependency', 'hostgroup_name', true],
            ['servicedependency', 'dependent_hostgroup_name', true],
            ['serviceescalation', 'hostgroup_name', true],
        ],
        'contact' => [  // contact_name changed
            ['contactgroup', 'members', true],
            ['host', 'contacts', true],
            ['service', 'contacts', true],
            ['hostescalation', 'contacts', true],
            ['serviceescalation', 'contacts', true],
        ],
        'contactgroup' => [  // contactgroup_name changed
            ['host', 'contact_groups', true],
            ['service', 'contact_groups', true],
            ['contact', 'contactgroups', true],
            ['contactgroup', 'contactgroup_members', true],
            ['hostescalation', 'contact_groups', true],
            ['serviceescalation', 'contact_groups', true],
        ],
        'timeperiod' => [  // timeperiod_name changed
            ['host', 'check_period', false],
            ['host', 'notification_period', false],
            ['service', 'check_period', false],
            ['service', 'notification_period', false],
            ['contact', 'host_notification_period', false],
            ['contact', 'service_notification_period', false],
            ['timeperiod', 'exclude', true],
            ['hostdependency', 'dependency_period', false],
            ['servicedependency', 'dependency_period', false],
            ['hostescalation', 'escalation_period', false],
            ['serviceescalation', 'escalation_period', false],
        ],
        'command' => [  // command_name changed
            ['host', 'check_command', 'command'],
            ['host', 'event_handler', 'command'],
            ['service', 'check_command', 'command'],
            ['service', 'event_handler', 'command'],
            ['contact', 'host_notification_commands', 'command_csv'],
            ['contact', 'service_notification_commands', 'command_csv'],
        ],
        'servicegroup' => [  // servicegroup_name changed
            ['service', 'servicegroups', true],
            ['servicegroup', 'servicegroup_members', true],
            ['servicedependency', 'servicegroup_name', true],
            ['servicedependency', 'dependent_servicegroup_name', true],
        ],
    ];
}

/**
 * Cascade a rename: update all references from oldName to newName.
 * Returns number of objects updated.
 */
function cascadeRename(
    array $config,
    NagiosWriter $writer,
    string $sourceType,
    string $oldName,
    string $newName
): int {
    $refMap = getRenameReferenceMap();
    if (!isset($refMap[$sourceType])) return 0;

    // Collect all objects that need updating (type, directive, file, line_start, identifier)
    $toUpdate = [];

    $parser_tmp = new NagiosParser($config);
    $parser_tmp->parse();

    foreach ($refMap[$sourceType] as [$targetType, $directive, $mode]) {
        foreach ($parser_tmp->getObjects() as $obj) {
            if ($obj['type'] !== $targetType) continue;
            $val = $obj['directives'][$directive] ?? null;
            if ($val === null || $val === '') continue;

            $matched = false;
            if ($mode === true) {
                // Comma-separated list
                $parts = array_map('trim', explode(',', $val));
                if (in_array($oldName, $parts, true)) $matched = true;
            } elseif ($mode === false) {
                // Simple value
                if ($val === $oldName) $matched = true;
            } elseif ($mode === 'command') {
                // command_name!arg1!arg2 — only match the part before first !
                $cmdParts = explode('!', $val, 2);
                if ($cmdParts[0] === $oldName) $matched = true;
            } elseif ($mode === 'command_csv') {
                // Comma-separated commands, each may have !args
                $cmds = array_map('trim', explode(',', $val));
                foreach ($cmds as $cmd) {
                    $cmdParts = explode('!', $cmd, 2);
                    if ($cmdParts[0] === $oldName) { $matched = true; break; }
                }
            } elseif ($mode === 'svcgroup_members') {
                // Paired format: host1,svc1,host2,svc2 — match host_name at even positions
                $parts = array_map('trim', explode(',', $val));
                for ($pi = 0; $pi < count($parts); $pi += 2) {
                    if ($parts[$pi] === $oldName) { $matched = true; break; }
                }
            }

            if ($matched) {
                // Build a unique identifier for this object
                $ident = $obj['file'] . ':' . $obj['line_start'];
                if (!isset($toUpdate[$ident])) {
                    $toUpdate[$ident] = [
                        'type' => $targetType,
                        'file' => $obj['file'],
                        'line_start' => $obj['line_start'],
                        'updates' => [],  // [directive => [mode, ...]]
                    ];
                }
                $toUpdate[$ident]['updates'][$directive] = $mode;
            }
        }
    }

    if (empty($toUpdate)) return 0;

    // Apply updates one by one, re-parsing between saves
    $updated = 0;
    foreach ($toUpdate as $ident => $info) {
        $parser_tmp = new NagiosParser($config);
        $parser_tmp->parse();

        $obj = $parser_tmp->findObjectByLocation($info['file'], $info['line_start']);
        if (!$obj) continue;

        $dirs = $obj['directives'];
        $changed = false;

        foreach ($info['updates'] as $directive => $mode) {
            $val = $dirs[$directive] ?? null;
            if ($val === null) continue;

            if ($mode === true) {
                $parts = array_map('trim', explode(',', $val));
                $newParts = array_map(fn($p) => $p === $oldName ? $newName : $p, $parts);
                $newVal = implode(',', $newParts);
            } elseif ($mode === false) {
                $newVal = ($val === $oldName) ? $newName : $val;
            } elseif ($mode === 'command') {
                $cmdParts = explode('!', $val, 2);
                if ($cmdParts[0] === $oldName) $cmdParts[0] = $newName;
                $newVal = implode('!', $cmdParts);
            } elseif ($mode === 'command_csv') {
                $cmds = array_map('trim', explode(',', $val));
                $newCmds = [];
                foreach ($cmds as $cmd) {
                    $cmdParts = explode('!', $cmd, 2);
                    if ($cmdParts[0] === $oldName) $cmdParts[0] = $newName;
                    $newCmds[] = implode('!', $cmdParts);
                }
                $newVal = implode(',', $newCmds);
            } elseif ($mode === 'svcgroup_members') {
                // Paired format: host1,svc1,host2,svc2 — replace at even positions
                $parts = array_map('trim', explode(',', $val));
                for ($pi = 0; $pi < count($parts); $pi += 2) {
                    if ($parts[$pi] === $oldName) $parts[$pi] = $newName;
                }
                $newVal = implode(',', $parts);
            } else {
                continue;
            }

            if ($newVal !== $val) {
                $dirs[$directive] = $newVal;
                $changed = true;
            }
        }

        if ($changed) {
            clearstatcache(true, $obj['file']);
            $wr = $writer->saveObject(
                $obj['file'], $obj['line_start'], $obj['line_end'],
                $obj['type'], $dirs, filemtime($obj['file'])
            );
            if ($wr['success']) $updated++;
        }
    }

    return $updated;
}

/**
 * Cascade a delete: remove dependent objects and clean up references.
 * Re-parses fresh before each operation to handle shifted line numbers.
 * Returns a summary array ['deleted' => int, 'cleaned' => int].
 */
function cascadeDelete(
    array $config,
    NagiosWriter $writer,
    string $sourceType,
    string $deletedName
): array {
    $refMap = getRenameReferenceMap();
    if (!isset($refMap[$sourceType])) return ['deleted' => 0, 'cleaned' => 0];

    $deleted = 0;
    $cleaned = 0;

    // Helper: check if a directive value references the deleted name
    $matchesRef = function(string $val, $mode) use ($deletedName): bool {
        if ($mode === true) {
            $parts = array_map('trim', explode(',', $val));
            return in_array($deletedName, $parts, true);
        } elseif ($mode === false) {
            return $val === $deletedName;
        } elseif ($mode === 'command') {
            return explode('!', $val, 2)[0] === $deletedName;
        } elseif ($mode === 'command_csv') {
            foreach (array_map('trim', explode(',', $val)) as $cmd) {
                if (explode('!', $cmd, 2)[0] === $deletedName) return true;
            }
        } elseif ($mode === 'svcgroup_members') {
            $parts = array_map('trim', explode(',', $val));
            for ($pi = 0; $pi < count($parts); $pi += 2) {
                if ($parts[$pi] === $deletedName) return true;
            }
        }
        return false;
    };

    // Phase 1: Delete dependent objects in a loop (re-parse each time)
    $found = true;
    while ($found) {
        $found = false;
        $parser_tmp = new NagiosParser($config);
        $parser_tmp->parse();

        foreach ($refMap[$sourceType] as [$targetType, $directive, $mode]) {
            foreach ($parser_tmp->getObjects() as $obj) {
                if ($obj['type'] !== $targetType) continue;
                $val = $obj['directives'][$directive] ?? null;
                if ($val === null || $val === '') continue;
                if (!$matchesRef($val, $mode)) continue;

                // Should this object be fully deleted?
                $shouldDelete = false;
                if ($mode === false) {
                    $shouldDelete = true;
                } elseif ($mode === true) {
                    $remaining = array_filter(array_map('trim', explode(',', $val)), fn($p) => $p !== $deletedName);
                    if (empty($remaining)) $shouldDelete = true;
                }
                if ($targetType === 'service' && $directive === 'host_name') {
                    $remaining = array_filter(array_map('trim', explode(',', $val)), fn($p) => $p !== $deletedName);
                    if (empty($remaining)) $shouldDelete = true;
                }

                if ($shouldDelete) {
                    clearstatcache(true, $obj['file']);
                    $dr = $writer->deleteObject($obj['file'], $obj['line_start'], $obj['line_end'], filemtime($obj['file']));
                    if ($dr['success']) $deleted++;
                    $found = true;
                    break 2; // restart outer loop with fresh parse
                }
            }
        }
    }

    // Phase 2: Clean references in a loop (re-parse each time)
    $found = true;
    while ($found) {
        $found = false;
        $parser_tmp = new NagiosParser($config);
        $parser_tmp->parse();

        foreach ($refMap[$sourceType] as [$targetType, $directive, $mode]) {
            foreach ($parser_tmp->getObjects() as $obj) {
                if ($obj['type'] !== $targetType) continue;
                $val = $obj['directives'][$directive] ?? null;
                if ($val === null || $val === '') continue;
                if (!$matchesRef($val, $mode)) continue;

                // Remove the name from the list
                $dirs = $obj['directives'];
                if ($mode === true) {
                    $parts = array_filter(array_map('trim', explode(',', $val)), fn($p) => $p !== $deletedName);
                    $newVal = implode(',', $parts);
                } elseif ($mode === 'svcgroup_members') {
                    $parts = array_map('trim', explode(',', $val));
                    $newParts = [];
                    for ($pi = 0; $pi < count($parts); $pi += 2) {
                        if ($parts[$pi] !== $deletedName) {
                            $newParts[] = $parts[$pi];
                            if (isset($parts[$pi + 1])) $newParts[] = $parts[$pi + 1];
                        }
                    }
                    $newVal = implode(',', $newParts);
                } elseif ($mode === 'command_csv') {
                    $cmds = array_filter(array_map('trim', explode(',', $val)), function($cmd) use ($deletedName) {
                        return explode('!', $cmd, 2)[0] !== $deletedName;
                    });
                    $newVal = implode(',', $cmds);
                } else {
                    continue;
                }

                if ($newVal !== $val) {
                    $dirs[$directive] = $newVal;
                    clearstatcache(true, $obj['file']);
                    $wr = $writer->saveObject($obj['file'], $obj['line_start'], $obj['line_end'], $obj['type'], $dirs, filemtime($obj['file']));
                    if ($wr['success']) $cleaned++;
                    $found = true;
                    break 2; // restart with fresh parse
                }
            }
        }
    }

    return ['deleted' => $deleted, 'cleaned' => $cleaned];
}

/**
 * Activate nagcfg template on all root templates that don't have it yet.
 * Returns the number of templates activated.
 */
function activateNagcfgAll(array $config, NagiosWriter $writer, string $tplType, string $nagcfgName): int
{
    $parser_tmp = new NagiosParser($config);
    $parser_tmp->parse();
    $allTpls = $parser_tmp->getObjectsByType($tplType, true);

    // First pass: collect names of templates that need activation
    $toActivate = [];
    foreach ($allTpls as $t) {
        $tName = $t['directives']['name'] ?? '';
        if ($tName === '' || $tName === $nagcfgName) continue;
        $tUses = array_map('trim', explode(',', $t['directives']['use'] ?? ''));
        if (in_array($nagcfgName, $tUses, true)) continue;
        $inherited = inheritsNagcfg($tName, $nagcfgName, $allTpls);
        if ($inherited) continue;
        $toActivate[] = $tName;
    }

    // Second pass: activate each, re-parsing to get fresh line numbers
    $activated = 0;
    foreach ($toActivate as $tName) {
        $parser_tmp = new NagiosParser($config);
        $parser_tmp->parse();
        $t = $parser_tmp->findObject($tplType, $tName);
        if (!$t) continue;

        $tUses = array_map('trim', explode(',', $t['directives']['use'] ?? ''));
        if (in_array($nagcfgName, $tUses, true)) continue;

        // Re-check inheritance (a parent may have been activated in this pass)
        $allTpls = $parser_tmp->getObjectsByType($tplType, true);
        if (inheritsNagcfg($tName, $nagcfgName, $allTpls)) continue;

        $dirs = $t['directives'];
        if (isset($dirs['use']) && $dirs['use'] !== '') {
            $dirs['use'] .= ',' . $nagcfgName;
        } else {
            $dirs['use'] = $nagcfgName;
        }
        clearstatcache(true, $t['file']);
        $wr = $writer->saveObject($t['file'], $t['line_start'], $t['line_end'], $tplType, $dirs, filemtime($t['file']));
        if ($wr['success']) {
            $activated++;
        }
    }

    return $activated;
}

/**
 * Get Nagios images directory path (derived from nagios_cfg location).
 */
function getNagiosImagesDir(array $config): ?string
{
    // /usr/local/nagios/etc/nagios.cfg → /usr/local/nagios/share/images
    $base = dirname(dirname($config['nagios_cfg']));
    $dir = $base . '/share/images';
    return is_dir($dir) ? $dir : null;
}

/**
 * Install custom action.gif (gear icon) into Nagios images dir.
 */
function installActionIcon(array $config): ?string
{
    $imagesDir = getNagiosImagesDir($config);
    if (!$imagesDir) return 'Nagios images directory not found.';

    $actionGif = $imagesDir . '/action.gif';
    $backup = $imagesDir . '/action-pre-nagcfg.gif';
    $gearSrc = __DIR__ . '/action-gear.gif';

    if (!is_file($gearSrc)) return 'Gear icon not found.';

    // Only backup once
    if (!is_file($backup) && is_file($actionGif)) {
        if (!@copy($actionGif, $backup)) {
            return 'Backup of action.gif failed.';
        }
    }

    if (!@copy($gearSrc, $actionGif)) {
        return 'Could not replace action.gif.';
    }
    return null; // success
}

/**
 * Restore original action.gif from backup.
 */
function uninstallActionIcon(array $config): ?string
{
    $imagesDir = getNagiosImagesDir($config);
    if (!$imagesDir) return null; // nothing to do

    $actionGif = $imagesDir . '/action.gif';
    $backup = $imagesDir . '/action-pre-nagcfg.gif';

    if (is_file($backup)) {
        if (!@copy($backup, $actionGif)) {
            return 'Could not restore action.gif.';
        }
        @unlink($backup);
    }
    return null; // success
}

/**
 * Get Nagios process info.
 */
function nagiosStatus(array $config): array
{
    $status = ['running' => false, 'pid' => null];

    $lockFile = '/usr/local/nagios/var/nagios.lock';
    $nagiosCfg = $config['nagios_cfg'];
    if (is_readable($nagiosCfg)) {
        $lines = file($nagiosCfg, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines ?: [] as $line) {
            if (preg_match('/^lock_file\s*=\s*(.+)$/', trim($line), $m)) {
                $lockFile = trim($m[1]);
                break;
            }
        }
    }

    if (is_readable($lockFile)) {
        $pid = trim(file_get_contents($lockFile));
        if ($pid && is_dir("/proc/$pid")) {
            $status['running'] = true;
            $status['pid'] = (int) $pid;
        }
    }

    return $status;
}

/**
 * Build all datalist option arrays for reference fields.
 */
function buildRefData(NagiosParser $parser): array
{
    $data = [];
    $types = ['host', 'hostgroup', 'service', 'servicegroup',
              'contact', 'contactgroup', 'command', 'timeperiod'];
    foreach ($types as $t) {
        $data[$t] = $parser->getAllNames($t);
    }
    // Also collect all template names per type
    foreach ($types as $t) {
        $data["tpl_$t"] = $parser->getAllNames($t, true);
    }
    return $data;
}

// ── HTML Output ─────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NagCFG<?= $view !== 'dashboard' ? ' — ' . h($view) : '' ?></title>
    <link rel="stylesheet" href="/nagcfg/style.css">
</head>
<body>
    <header>
        <div class="header-inner">
            <a href="/nagcfg/" class="logo">NagCFG</a>
            <nav>
                <a href="/nagcfg/"<?= activeClass($view, 'dashboard') ?>>Dashboard</a>
                <a href="/nagcfg/?view=files"<?= activeClass($view, 'files') ?>>Files</a>
                <a href="/nagcfg/?view=backups"<?= activeClass($view, 'backups') ?>>Backups</a>
                <a href="/nagcfg/?view=validate"<?= activeClass($view, 'validate') ?>>Validate</a>
                <a href="/nagcfg/?view=settings"<?= activeClass($view, 'settings') ?>>Settings</a>
            </nav>
            <button id="theme-toggle" type="button" title="Toggle theme"></button>
        </div>
    </header>

    <main>
<?php if ($flashMessage): ?>
        <div class="alert alert-<?= h($flashMessage['type']) ?>">
            <?= h($flashMessage['text']) ?>
            <?php if (!empty($flashMessage['details'])): ?>
            <details><summary>Details</summary><pre><?= h($flashMessage['details']) ?></pre></details>
            <?php endif; ?>
        </div>
<?php endif; ?>
<?php

// ── Dashboard ───────────────────────────────────────────────────────────
if ($view === 'dashboard'):
    $nagios = nagiosStatus($config);
    $mainTypes = ['host','service','command','contact','contactgroup','timeperiod','hostgroup','servicegroup'];
    $secondaryTypes = ['hostdependency','hostescalation','servicedependency','serviceescalation'];
?>
        <h2>Dashboard</h2>

        <?php if ($parser->getErrors()): ?>
        <div class="alert alert-error">
            <strong>Parser errors:</strong>
            <ul>
            <?php foreach ($parser->getErrors() as $err): ?>
                <li><?= h($err) ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="tiles">
        <?php foreach ($mainTypes as $t):
            $count = count($parser->getObjectsByType($t, false));
            $tplCount = count($parser->getObjectsByType($t, true));
        ?>
            <a href="/nagcfg/?view=list&type=<?= h($t) ?>" class="tile">
                <span class="tile-count"><?= $count ?></span>
                <span class="tile-label"><?= typeLabel($t) ?></span>
                <?php if ($tplCount > 0): ?>
                <span class="tile-templates"><?= $tplCount ?> Template<?= $tplCount !== 1 ? 's' : '' ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
        </div>

        <?php
        $hasSecondary = false;
        foreach ($secondaryTypes as $t) {
            if (count($parser->getObjectsByType($t)) > 0) { $hasSecondary = true; break; }
        }
        if ($hasSecondary):
        ?>
        <h3>Advanced</h3>
        <div class="tiles tiles-small">
        <?php foreach ($secondaryTypes as $t):
            $count = count($parser->getObjectsByType($t));
            if ($count === 0) continue;
        ?>
            <a href="/nagcfg/?view=list&type=<?= h($t) ?>" class="tile tile-small">
                <span class="tile-count"><?= $count ?></span>
                <span class="tile-label"><?= typeLabel($t) ?></span>
            </a>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="actions-bar">
            <a href="/nagcfg/?view=validate" class="btn">Validate configuration</a>
            <?php if (!$config['readonly']): ?>
            <form method="post" action="/nagcfg/?action=reload" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <button type="submit" class="btn btn-primary" onclick="return confirm('Reload Nagios?')">Reload Nagios</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="status-bar">
            <span class="status-indicator <?= $nagios['running'] ? 'status-ok' : 'status-critical' ?>"></span>
            Nagios: <?= $nagios['running'] ? 'Running (PID' . $nagios['pid'] . ')' : 'Not running' ?>
            &middot; <?= count($parser->getFiles()) ?> Config Files
            &middot; <?= count($parser->getObjects()) ?> objects
        </div>

<?php
// ── List View ───────────────────────────────────────────────────────────
elseif ($view === 'list'):
    if (!$type || !in_array($type, NagiosParser::getValidTypes(), true)) {
        echo '<div class="alert alert-error">Unknown object type.</div>';
    } else {
        $objects = $parser->getObjectsByType($type);
        $columns = listColumns($type);
        $label = typeLabel($type);
?>
        <div class="list-header">
            <h2><?= $label ?> <span class="count">(<?= count($objects) ?>)</span></h2>
            <div class="list-actions">
                <input type="text" id="search" placeholder="Search..." class="search-input" autofocus>
                <?php if (!$config['readonly']): ?>
                <a href="/nagcfg/?view=new&type=<?= h($type) ?>" class="btn btn-primary">New</a>
                <?php endif; ?>
            </div>
        </div>

        <table class="obj-table" id="obj-table">
            <thead>
                <tr>
                <?php foreach ($columns as $col): ?>
                    <th data-sort="<?= h($col[0]) ?>"><?= h($col[1]) ?></th>
                <?php endforeach; ?>
                    <th>File</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($objects as $obj):
                $isTemplate = $parser->isTemplate($obj);
                $url = editUrl($obj, $parser);
            ?>
                <tr class="<?= $isTemplate ? 'template-row' : '' ?>">
                <?php foreach ($columns as $i => $col):
                    $val = $obj['directives'][$col[0]] ?? '';
                    // For templates: show the 'name' directive in the first column
                    if ($isTemplate && $i === 0 && $val === '') {
                        $val = $obj['directives']['name'] ?? '';
                    }
                ?>
                    <td>
                    <?php if ($i === 0): ?>
                        <a href="<?= h($url) ?>"><?= h($val) ?: '<em>—</em>' ?></a>
                        <?php if ($isTemplate): ?><span class="badge">Template</span><?php endif; ?>
                    <?php else: ?>
                        <?= $val !== '' ? linkedValue($val, $col[0], $type, $col[2]) : '' ?>
                    <?php endif; ?>
                    </td>
                <?php endforeach; ?>
                    <td class="file-col"><?= h(basename($obj['file'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
<?php
    }

// ── Edit View ───────────────────────────────────────────────────────────
elseif ($view === 'edit'):
    // Resolve the object
    $obj = null;
    if ($type === 'service') {
        $obj = $parser->findObject('service', $_GET['host'] ?? '', $_GET['desc'] ?? '');
    } elseif (isset($_GET['file'], $_GET['line'])) {
        $obj = $parser->findObjectByLocation($_GET['file'], (int)$_GET['line']);
    } elseif (isset($_GET['name'])) {
        $obj = $parser->findObject($type, $_GET['name']);
    }

    if (!$obj):
?>
        <div class="alert alert-error">Object not found.</div>
<?php else:
        $refData = buildRefData($parser);
        $knownDirectives = directivesForType($type);

        // Related services (for hosts)
        $relatedServices = [];
        if ($type === 'host') {
            $hostName = $obj['directives']['host_name'] ?? '';
            if ($hostName !== '') {
                foreach ($parser->getObjectsByType('service') as $svc) {
                    $svcHost = $svc['directives']['host_name'] ?? '';
                    // host_name can be comma-separated
                    $svcHosts = array_map('trim', explode(',', $svcHost));
                    if (in_array($hostName, $svcHosts, true)) {
                        $relatedServices[] = $svc;
                    }
                }
            }
        }

        // Collect which datalists we need
        $neededDataLists = [];
        foreach ($obj['directives'] as $key => $val) {
            $ref = refMap($key, $type);
            if ($ref === '__templates__') {
                $neededDataLists["tpl_$type"] = true;
            } elseif ($ref && isset($refData[$ref])) {
                $neededDataLists[$ref] = true;
            }
        }
        // Also for potential new directives
        foreach ($knownDirectives as $dir) {
            $ref = refMap($dir, $type);
            if ($ref === '__templates__') {
                $neededDataLists["tpl_$type"] = true;
            } elseif ($ref && isset($refData[$ref])) {
                $neededDataLists[$ref] = true;
            }
        }
?>
        <div class="edit-header">
            <h2><?= h(typeLabel($type)) ?>: <?= h($parser->getDisplayName($obj)) ?></h2>
            <span class="meta">
                <?= h($obj['file']) ?> (line <?= $obj['line_start'] ?>–<?= $obj['line_end'] ?>)
            </span>
        </div>

        <!-- Datalists for reference fields -->
        <?php foreach ($neededDataLists as $dlKey => $_):
            $options = $refData[$dlKey] ?? [];
        ?>
        <datalist id="dl-<?= h($dlKey) ?>">
            <?php foreach ($options as $opt): ?>
            <option value="<?= h($opt) ?>">
            <?php endforeach; ?>
        </datalist>
        <?php endforeach; ?>

        <!-- Datalist for directive names -->
        <datalist id="dl-directives">
            <?php foreach ($knownDirectives as $dir): ?>
            <option value="<?= h($dir) ?>">
            <?php endforeach; ?>
        </datalist>

        <form method="post" action="/nagcfg/?action=save" id="edit-form">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="type" value="<?= h($type) ?>">
            <input type="hidden" name="file" value="<?= h($obj['file']) ?>">
            <input type="hidden" name="line_start" value="<?= $obj['line_start'] ?>">
            <input type="hidden" name="line_end" value="<?= $obj['line_end'] ?>">
            <input type="hidden" name="filemtime" value="<?= filemtime($obj['file']) ?>">
            <?php
            // Pass original key for rename detection
            $keyField = NagiosParser::getKeyField($type);
            $oldKey = null;
            if ($type === 'service') {
                $oldKey = $obj['directives']['host_name'] ?? null;
                $oldKey2 = $obj['directives']['service_description'] ?? null;
            } elseif ($keyField) {
                $oldKey = $obj['directives'][$keyField] ?? null;
            }
            // Also track template name
            $oldTplName = $obj['directives']['name'] ?? null;
            ?>
            <?php if ($oldKey !== null): ?>
            <input type="hidden" name="old_key" value="<?= h($oldKey) ?>">
            <?php endif; ?>
            <?php if ($type === 'service' && isset($oldKey2)): ?>
            <input type="hidden" name="old_key2" value="<?= h($oldKey2) ?>">
            <?php endif; ?>
            <?php if ($oldTplName !== null): ?>
            <input type="hidden" name="old_tpl_name" value="<?= h($oldTplName) ?>">
            <?php endif; ?>

            <table class="edit-table">
                <thead>
                    <tr><th>Directive</th><th>Value</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($obj['directives'] as $key => $val):
                    $ref = refMap($key, $type);
                    $dlId = '';
                    if ($ref === '__templates__') $dlId = "dl-tpl_$type";
                    elseif ($ref && isset($refData[$ref])) $dlId = "dl-$ref";
                ?>
                    <tr>
                        <td><input type="text" name="keys[]" value="<?= h($key) ?>" class="input-key" readonly></td>
                        <td>
                            <input type="text" name="values[]" value="<?= h($val) ?>" class="input-value"
                                <?= $dlId ? 'list="' . h($dlId) . '"' : '' ?>
                                data-directive="<?= h($key) ?>">
                            <?php if ($ref && $ref !== '__templates__' && $val !== ''):
                                // Show clickable links below input
                                $parts = array_map('trim', explode(',', $val));
                                $linkType = $ref;
                                // For commands with !args, extract command name
                                if (in_array($key, ['check_command', 'event_handler'])) {
                                    $parts = [explode('!', $val, 2)[0]];
                                    $linkType = 'command';
                                }
                            ?>
                            <div class="ref-links">
                                <?php foreach ($parts as $p): if ($p === '') continue; ?>
                                <a href="<?= h(refEditUrl($linkType, $p)) ?>" class="ref-badge" title="<?= h($p) ?> edit"><?= h($p) ?></a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($ref === '__templates__' && $val !== ''):
                                $parts = array_map('trim', explode(',', $val));
                            ?>
                            <div class="ref-links">
                                <?php foreach ($parts as $p): if ($p === '') continue; ?>
                                <a href="<?= h(refEditUrl($type, $p)) ?>" class="ref-badge" title="Template '<?= h($p) ?>' edit"><?= h($p) ?></a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><button type="button" class="btn-remove" title="Remove">&times;</button></td>
                    </tr>
                <?php endforeach; ?>
                    <tr class="new-directive-row">
                        <td><input type="text" name="keys[]" value="" class="input-key" placeholder="New directive" list="dl-directives" id="new-directive-key"></td>
                        <td><input type="text" name="values[]" value="" class="input-value" placeholder="Value" id="new-directive-value"></td>
                        <td><button type="button" class="btn-add" title="Add another">+</button></td>
                    </tr>
                </tbody>
            </table>

            <div class="form-actions">
                <?php if (!$config['readonly']): ?>
                <button type="submit" name="action" value="save" class="btn btn-primary">Save</button>
                <?php endif; ?>
                <a href="/nagcfg/?view=list&type=<?= h($type) ?>" class="btn">Cancel</a>
                <a href="/nagcfg/?view=new&type=<?= h($type) ?>&copy_file=<?= urlencode($obj['file']) ?>&copy_line=<?= $obj['line_start'] ?>" class="btn" style="margin-left:auto">Copy</a>
            </div>
        </form>

        <?php if (!$config['readonly']): ?>
        <details class="delete-section">
            <summary>Delete object</summary>
            <form method="post" action="/nagcfg/?action=delete" class="delete-form">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="type" value="<?= h($type) ?>">
                <input type="hidden" name="file" value="<?= h($obj['file']) ?>">
                <input type="hidden" name="line_start" value="<?= $obj['line_start'] ?>">
                <input type="hidden" name="line_end" value="<?= $obj['line_end'] ?>">
                <input type="hidden" name="filemtime" value="<?= filemtime($obj['file']) ?>">
                <input type="hidden" name="comments" value="<?= h($obj['comments']) ?>">
                <input type="hidden" name="expected_name" value="<?= h($parser->getDisplayName($obj)) ?>">
                <div class="alert alert-warning">
                    Type the name <strong><?= h($parser->getDisplayName($obj)) ?></strong> to confirm:
                </div>
                <div class="delete-confirm">
                    <input type="text" name="confirm_name" class="search-input" placeholder="<?= h($parser->getDisplayName($obj)) ?>" autocomplete="off">
                    <button type="submit" class="btn btn-danger">Delete permanently</button>
                </div>
            </form>
        </details>
        <?php endif; ?>

        <details class="raw-view">
            <summary>Show raw</summary>
            <pre class="raw-block"><?= h($obj['raw']) ?></pre>
        </details>

        <?php if ($type === 'host' && count($relatedServices) > 0): ?>
        <div class="related-section">
            <h3>Related Services <span class="count">(<?= count($relatedServices) ?>)</span></h3>
            <table class="obj-table">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Check Command</th>
                        <th>Template</th>
                        <th>File</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($relatedServices as $svc):
                    $svcUrl = editUrl($svc, $parser);
                    $svcDesc = $svc['directives']['service_description'] ?? '?';
                    $svcCmd = $svc['directives']['check_command'] ?? '';
                    $svcUse = $svc['directives']['use'] ?? '';
                ?>
                    <tr>
                        <td><a href="<?= h($svcUrl) ?>"><?= h($svcDesc) ?></a></td>
                        <td><?= $svcCmd ? linkedValue($svcCmd, 'check_command', 'service', 40) : '' ?></td>
                        <td><?= $svcUse ? linkedValue($svcUse, 'use', 'service') : '' ?></td>
                        <td class="file-col"><?= h(basename($svc['file'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Reference data for JS -->
        <script>
        var NagCFG = {
            objectType: <?= json_encode($type) ?>,
            refMap: <?= json_encode((object) array_filter(
                array_combine(
                    $knownDirectives,
                    array_map(fn($d) => refMap($d, $type), $knownDirectives)
                ),
                fn($v) => $v !== null
            )) ?>,
            refData: <?= json_encode($refData) ?>
        };
        </script>
<?php
    endif;

// ── New View ────────────────────────────────────────────────────────────
elseif ($view === 'new'):
    if (!$type || !in_array($type, NagiosParser::getValidTypes(), true)) {
        echo '<div class="alert alert-error">Unknown object type.</div>';
    } elseif ($config['readonly'] ?? false) {
        echo '<div class="alert alert-error">Read-only mode active.</div>';
    } else {
        $refData = buildRefData($parser);
        $knownDirectives = directivesForType($type);
        $templates = $parser->getAllNames($type, true);
        $isTemplateMode = isset($_GET['template']);

        // Collect target files: files that already contain objects of this type
        $targetFiles = [];
        foreach ($parser->getFiles() as $f) {
            $targetFiles[$f] = basename($f);
        }

        // Copy mode: prefill from existing object
        $prefill = [];
        if (isset($_GET['copy_file'], $_GET['copy_line'])) {
            $copyObj = $parser->findObjectByLocation($_GET['copy_file'], (int)$_GET['copy_line']);
            if ($copyObj) {
                $prefill = $copyObj['directives'];
                // Remove identity fields for the copy
                $keyField = NagiosParser::getKeyField($type);
                if ($keyField) unset($prefill[$keyField]);
                if ($type === 'service') unset($prefill['service_description']);
                unset($prefill['name']); // template name must be unique
            }
        }

        if ($isTemplateMode && !isset($prefill['register'])) {
            $prefill['register'] = '0';
        }

        // Collect datalists
        $neededDataLists = [];
        foreach ($knownDirectives as $dir) {
            $ref = refMap($dir, $type);
            if ($ref === '__templates__') $neededDataLists["tpl_$type"] = true;
            elseif ($ref && isset($refData[$ref])) $neededDataLists[$ref] = true;
        }
?>
        <h2>New object: <?= h(typeLabel($type)) ?><?= $isTemplateMode ? ' (Template)' : '' ?></h2>

        <?php foreach ($neededDataLists as $dlKey => $_):
            $options = $refData[$dlKey] ?? [];
        ?>
        <datalist id="dl-<?= h($dlKey) ?>">
            <?php foreach ($options as $opt): ?>
            <option value="<?= h($opt) ?>">
            <?php endforeach; ?>
        </datalist>
        <?php endforeach; ?>

        <datalist id="dl-directives">
            <?php foreach ($knownDirectives as $dir): ?>
            <option value="<?= h($dir) ?>">
            <?php endforeach; ?>
        </datalist>

        <form method="post" action="/nagcfg/?action=create" id="edit-form">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="type" value="<?= h($type) ?>">

            <div class="new-options">
                <?php if (count($templates) > 0): ?>
                <label>
                    Based on template:
                    <select id="template-select" class="search-input">
                        <option value="">— No template —</option>
                        <?php foreach ($templates as $tpl): ?>
                        <option value="<?= h($tpl) ?>"><?= h($tpl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
                <label>
                    Target file:
                    <select name="target_file" class="search-input" required>
                        <?php foreach ($targetFiles as $path => $name): ?>
                        <option value="<?= h($path) ?>"><?= h($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <table class="edit-table">
                <thead>
                    <tr><th>Directive</th><th>Value</th><th></th></tr>
                </thead>
                <tbody>
                <?php if (!empty($prefill)):
                    foreach ($prefill as $key => $val):
                        $ref = refMap($key, $type);
                        $dlId = '';
                        if ($ref === '__templates__') $dlId = "dl-tpl_$type";
                        elseif ($ref && isset($refData[$ref])) $dlId = "dl-$ref";
                ?>
                    <tr>
                        <td><input type="text" name="keys[]" value="<?= h($key) ?>" class="input-key" readonly></td>
                        <td><input type="text" name="values[]" value="<?= h($val) ?>" class="input-value"
                            <?= $dlId ? 'list="' . h($dlId) . '"' : '' ?>></td>
                        <td><button type="button" class="btn-remove" title="Remove">&times;</button></td>
                    </tr>
                <?php endforeach; endif; ?>
                    <tr class="new-directive-row">
                        <td><input type="text" name="keys[]" value="" class="input-key" placeholder="New directive" list="dl-directives"></td>
                        <td><input type="text" name="values[]" value="" class="input-value" placeholder="Value"></td>
                        <td><button type="button" class="btn-add" title="Add another">+</button></td>
                    </tr>
                </tbody>
            </table>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create</button>
                <a href="/nagcfg/?view=list&type=<?= h($type) ?>" class="btn">Cancel</a>
            </div>
        </form>

        <script>
        var NagCFG = {
            objectType: <?= json_encode($type) ?>,
            refMap: <?= json_encode((object) array_filter(
                array_combine(
                    $knownDirectives,
                    array_map(fn($d) => refMap($d, $type), $knownDirectives)
                ),
                fn($v) => $v !== null
            )) ?>,
            refData: <?= json_encode($refData) ?>
        };

        // Template selection: auto-set 'use' directive
        var tplSelect = document.getElementById('template-select');
        if (tplSelect) {
            tplSelect.addEventListener('change', function() {
                var tbody = document.querySelector('#edit-form tbody');
                if (!this.value) return;
                // Check if 'use' row exists
                var rows = tbody.querySelectorAll('tr:not(.new-directive-row)');
                var useRow = null;
                for (var i = 0; i < rows.length; i++) {
                    var keyInput = rows[i].querySelector('.input-key');
                    if (keyInput && keyInput.value === 'use') {
                        useRow = rows[i];
                        break;
                    }
                }
                if (useRow) {
                    useRow.querySelector('.input-value').value = this.value;
                } else {
                    // Add a 'use' row before the new-directive row
                    var newRow = document.querySelector('.new-directive-row');
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td><input type="text" name="keys[]" value="use" class="input-key" readonly></td>'
                        + '<td><input type="text" name="values[]" value="' + this.value.replace(/"/g, '&quot;') + '" class="input-value"></td>'
                        + '<td><button type="button" class="btn-remove" title="Remove">&times;</button></td>';
                    tbody.insertBefore(tr, newRow);
                }
            });
        }
        </script>
<?php
    }

// ── Files View ──────────────────────────────────────────────────────────
elseif ($view === 'files'):
    $files = $parser->getFiles();
?>
        <h2>Config Files <span class="count">(<?= count($files) ?>)</span></h2>

        <table class="obj-table">
            <thead>
                <tr>
                    <th>File</th>
                    <th>objects</th>
                    <th>Size</th>
                    <th>Last Modified</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($files as $f):
                $objCount = 0;
                foreach ($parser->getObjects() as $o) {
                    if ($o['file'] === $f) $objCount++;
                }
                $stat = @stat($f);
            ?>
                <tr>
                    <td><a href="/nagcfg/?view=raw&file=<?= urlencode($f) ?>"><?= h($f) ?></a></td>
                    <td><?= $objCount ?></td>
                    <td><?= $stat ? number_format($stat['size'] / 1024, 1) . ' KB' : '?' ?></td>
                    <td><?= $stat ? date('d.m.Y H:i', $stat['mtime']) : '?' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

<?php
// ── Validate View ───────────────────────────────────────────────────────
elseif ($view === 'validate'):
    $output = [];
    $returnCode = -1;
    $nagiosBin = $config['nagios_bin'];
    $nagiosCfg = $config['nagios_cfg'];

    if (is_executable($nagiosBin)) {
        exec($nagiosBin . ' -v ' . escapeshellarg($nagiosCfg) . ' 2>&1', $output, $returnCode);
    } else {
        $output = ["Nagios binary not executable: $nagiosBin"];
    }
?>
        <h2>Configuration Validation</h2>

        <div class="validate-result <?= $returnCode === 0 ? 'validate-ok' : 'validate-error' ?>">
            <?= $returnCode === 0 ? 'Configuration OK' : 'Errors found' ?>
        </div>

        <pre class="validate-output"><?php
            foreach ($output as $line) {
                if (preg_match('/error/i', $line)) {
                    echo '<span class="line-error">' . h($line) . "</span>\n";
                } elseif (preg_match('/warning/i', $line)) {
                    echo '<span class="line-warning">' . h($line) . "</span>\n";
                } else {
                    echo h($line) . "\n";
                }
            }
        ?></pre>

        <?php if ($returnCode === 0 && !$config['readonly']): ?>
        <form method="post" action="/nagcfg/?action=reload">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <button type="submit" class="btn btn-primary" onclick="return confirm('Reload Nagios?')">Reload Nagios</button>
        </form>
        <?php endif; ?>

<?php
// ── Raw View ────────────────────────────────────────────────────────────
elseif ($view === 'raw'):
    $file = $_GET['file'] ?? '';
    $allowed = false;
    foreach ($parser->getFiles() as $f) {
        if ($f === $file) { $allowed = true; break; }
    }
    if (!$allowed || !is_readable($file)):
?>
        <div class="alert alert-error">File not found or not allowed.</div>
<?php else:
        $content = file_get_contents($file);
?>
        <div class="raw-header">
            <h2><?= h(basename($file)) ?></h2>
            <span class="meta"><?= h($file) ?></span>
        </div>

        <?php if (!$config['readonly']): ?>
        <form method="post" action="/nagcfg/?action=save_raw">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="file" value="<?= h($file) ?>">
            <input type="hidden" name="filemtime" value="<?= filemtime($file) ?>">
            <div class="alert alert-warning">Changes in raw view bypass form validation.</div>
            <textarea name="content" class="raw-editor" spellcheck="false"><?= h($content) ?></textarea>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save</button>
                <a href="/nagcfg/?view=files" class="btn">Back</a>
            </div>
        </form>
        <?php else: ?>
        <pre class="raw-editor-readonly"><?= h($content) ?></pre>
        <?php endif; ?>
<?php
    endif;

// ── Settings View ───────────────────────────────────────────────────────
elseif ($view === 'settings'):
    clearstatcache();
    $checks = [
        ['nagios.cfg',     $config['nagios_cfg'], 'read'],
        ['Nagios-Binary',  $config['nagios_bin'], 'exec'],
        ['Command-Pipe',   $config['nagios_cmd'], 'write'],
        ['Backup Dir',     $config['backup_dir'], 'write'],
    ];
    foreach ($parser->getFiles() as $f) {
        $checks[] = [basename($f), $f, 'write'];
    }
?>
        <h2>Settings</h2>

        <h3>Configuration</h3>
        <table class="obj-table">
            <tbody>
            <?php foreach ($config as $key => $val): ?>
                <tr>
                    <td><strong><?= h($key) ?></strong></td>
                    <td><?= h(is_bool($val) ? ($val ? 'true' : 'false') : (string) $val) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3>Permissions</h3>
        <table class="obj-table">
            <thead>
                <tr><th>Path</th><th>Required</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($checks as [$label, $path, $need]):
                if ($need === 'read')       $ok = is_readable($path);
                elseif ($need === 'exec')   $ok = is_executable($path);
                elseif ($need === 'write')  $ok = is_writable($path);
                else                        $ok = file_exists($path);
            ?>
                <tr>
                    <td><?= h($path) ?> <span class="meta">(<?= h($label) ?>)</span></td>
                    <td><?= h($need) ?></td>
                    <td><span class="status-indicator <?= $ok ? 'status-ok' : 'status-critical' ?>"></span> <?= $ok ? 'OK' : 'Missing' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3 id="actionurl">Nagios action_url Integration</h3>
        <p>Links the Nagios web interface to NagCFG: every host and service gets an edit link.</p>
        <?php
        $nagcfgHostTpl = $parser->findObject('host', 'nagcfg-host');
        $nagcfgServiceTpl = $parser->findObject('service', 'nagcfg-service');
        $auInstalled = $nagcfgHostTpl && $nagcfgServiceTpl;
        $auPartial = ($nagcfgHostTpl || $nagcfgServiceTpl) && !$auInstalled;
        $auNone = !$nagcfgHostTpl && !$nagcfgServiceTpl;

        // Collect per-template status for details
        $actionUrlStatus = [];
        $totalInactive = 0;
        foreach (['host' => $nagcfgHostTpl, 'service' => $nagcfgServiceTpl] as $auType => $auTpl) {
            $templates = $parser->getObjectsByType($auType, true);
            $auNagcfgName = "nagcfg-$auType";
            $total = 0; $active = 0; $inherited = 0; $inactive = 0;
            $details = [];
            if ($auTpl) {
                foreach ($templates as $t) {
                    $tName = $t['directives']['name'] ?? '';
                    if ($tName === $auNagcfgName) continue;
                    $total++;
                    $uses = array_map('trim', explode(',', $t['directives']['use'] ?? ''));
                    if (in_array($auNagcfgName, $uses, true)) {
                        $active++;
                        $details[] = ['name' => $tName, 'status' => 'active'];
                    } else {
                        $via = null;
                        foreach ($uses as $parent) {
                            if ($parent === '') continue;
                            $via = inheritsNagcfg($parent, $auNagcfgName, $templates);
                            if ($via) break;
                        }
                        if ($via) {
                            $inherited++;
                            $details[] = ['name' => $tName, 'status' => 'inherited', 'via' => $via];
                        } else {
                            $inactive++;
                            $details[] = ['name' => $tName, 'status' => 'inactive'];
                        }
                    }
                }
            }
            $actionUrlStatus[$auType] = compact('total', 'active', 'inherited', 'inactive', 'details');
            $totalInactive += $inactive;
        }
        ?>
        <p>
            <?php if ($auInstalled): ?>
                <span class="status-indicator status-ok"></span> <strong>Installed</strong>
                <?php
                $hostOk = $actionUrlStatus['host']['active'] + $actionUrlStatus['host']['inherited'];
                $hostTotal = $actionUrlStatus['host']['total'];
                $svcOk = $actionUrlStatus['service']['active'] + $actionUrlStatus['service']['inherited'];
                $svcTotal = $actionUrlStatus['service']['total'];
                ?>
                &mdash; Host-Templates: <?= $hostOk ?>/<?= $hostTotal ?>,
                Service-Templates: <?= $svcOk ?>/<?= $svcTotal ?>
            <?php elseif ($auPartial): ?>
                <span class="status-indicator status-warning"></span> <strong>Partially installed</strong>
                (<?= $nagcfgHostTpl ? 'host only' : 'service only' ?>)
            <?php else: ?>
                <span class="status-indicator status-critical"></span> <strong>Not installed</strong>
            <?php endif; ?>
        </p>
        <?php if (!($config['readonly'] ?? false)): ?>
        <p>
            <?php if ($auNone || $auPartial): ?>
            <form method="post" action="/nagcfg/?action=actionurl" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="step" value="install">
                <button type="submit" class="btn btn-primary">Install</button>
            </form>
            <?php endif; ?>
            <?php if ($auInstalled && $totalInactive > 0): ?>
            <form method="post" action="/nagcfg/?action=actionurl" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="step" value="activate_all">
                <button type="submit" class="btn btn-primary">Activate All</button>
            </form>
            <?php endif; ?>
            <?php if ($auInstalled || $auPartial): ?>
            <form method="post" action="/nagcfg/?action=actionurl" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="step" value="uninstall">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Remove action_url templates and all references?')">Uninstall</button>
            </form>
            <?php endif; ?>
        </p>
        <?php endif; ?>
        <?php
        // Show details for installed templates
        $hasDetails = false;
        foreach (['host', 'service'] as $auType) {
            $auTpl = $auType === 'host' ? $nagcfgHostTpl : $nagcfgServiceTpl;
            if ($auTpl && $actionUrlStatus[$auType]['total'] > 0) { $hasDetails = true; break; }
        }
        if ($hasDetails): ?>
        <details style="margin-top: 8px">
            <summary class="meta">Show inheritance status</summary>
            <table class="obj-table" style="margin-top: 4px">
                <thead><tr><th>Template</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach (['host', 'service'] as $auType):
                        $auTpl = $auType === 'host' ? $nagcfgHostTpl : $nagcfgServiceTpl;
                        if (!$auTpl) continue;
                        foreach ($actionUrlStatus[$auType]['details'] as $d): ?>
                    <tr>
                        <td><code><?= h($d['name']) ?></code> <span class="meta">(<?= $auType ?>)</span></td>
                        <td>
                            <?php if ($d['status'] === 'active'): ?>
                                <span class="status-indicator status-ok"></span> Active
                            <?php elseif ($d['status'] === 'inherited'): ?>
                                <span class="status-indicator status-ok"></span> Inherited <span class="meta">(via <?= h($d['via']) ?>)</span>
                            <?php else: ?>
                                <span class="status-indicator status-critical"></span> Inactive
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        </details>
        <?php endif; ?>

<?php
// ── Backups View ────────────────────────────────────────────────────────
elseif ($view === 'backups'):
    $backupDir = rtrim($config['backup_dir'], '/');
    $transactions = [];
    if (is_dir($backupDir)) {
        $scan = scandir($backupDir);
        foreach ($scan as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (!str_ends_with($entry, '.bak')) continue;
            $fullPath = $backupDir . '/' . $entry;
            // New format: name.cfg_2026-05-17_15-44-06_a1b2c3d4.bak
            if (preg_match('/^(.+?)_(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})_([a-f0-9]+)\.bak$/', $entry, $m)) {
                $txId = $m[6];
                $dateStr = $m[2] . ' ' . $m[3] . ':' . $m[4] . ':' . $m[5];
                $transactions[$txId]['date'] = $dateStr;
                $transactions[$txId]['timestamp'] = strtotime($dateStr);
                $transactions[$txId]['files'][] = [
                    'origName' => $m[1],
                    'file' => $fullPath,
                    'size' => filesize($fullPath),
                ];
            }
            // Old format (no tx ID): name.cfg_2026-05-17_15-44-06.bak
            elseif (preg_match('/^(.+?)_(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})\.bak$/', $entry, $m)) {
                $txId = 'legacy_' . $entry;
                $dateStr = $m[2] . ' ' . $m[3] . ':' . $m[4] . ':' . $m[5];
                $transactions[$txId]['date'] = $dateStr;
                $transactions[$txId]['timestamp'] = strtotime($dateStr);
                $transactions[$txId]['files'][] = [
                    'origName' => $m[1],
                    'file' => $fullPath,
                    'size' => filesize($fullPath),
                ];
            }
        }
    }
    // Sort transactions by timestamp descending (newest first)
    uasort($transactions, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
?>
        <h2>Backups</h2>

        <?php if (empty($transactions)): ?>
            <p>No backups found in <code><?= h($backupDir) ?></code>.</p>
        <?php else: ?>
            <table class="obj-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Files</th>
                        <th>Total Size</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($transactions as $txId => $tx):
                    $fileNames = array_map(fn($f) => $f['origName'], $tx['files']);
                    $totalSize = array_sum(array_map(fn($f) => $f['size'], $tx['files']));
                    $filePaths = array_map(fn($f) => $f['file'], $tx['files']);
                ?>
                    <tr>
                        <td><?= h(date('d.m.Y H:i:s', $tx['timestamp'])) ?></td>
                        <td><?= h(implode(', ', $fileNames)) ?></td>
                        <td><?= number_format($totalSize / 1024, 1) ?> KB</td>
                        <td>
                            <form method="post" action="/nagcfg/?action=restore" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                                <?php foreach ($filePaths as $fp): ?>
                                    <input type="hidden" name="backup_files[]" value="<?= h($fp) ?>">
                                <?php endforeach; ?>
                                <button type="submit" class="btn btn-small" onclick="return confirm('Restore <?= h(implode(', ', $fileNames)) ?> from <?= h(date('d.m.Y H:i:s', $tx['timestamp'])) ?>?\nCurrent files will be backed up first.')">Restore</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

<?php
// ── Unknown View ────────────────────────────────────────────────────────
else:
?>
        <div class="alert alert-error">Unknown view: <?= h($view) ?></div>
<?php endif; ?>
    </main>

    <script src="/nagcfg/script.js"></script>
</body>
</html>
