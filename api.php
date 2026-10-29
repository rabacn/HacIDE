<?php
// TEMPORARY DEBUGGING BLOCK
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('pcre.jit', 0);
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json');

// 1. AUTOLOAD COMPILER CLASSES
spl_autoload_register(function ($class) {
    $prefix = 'Asm\\';
    $baseDir = __DIR__ . '/../src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require_once $file;
});

$compilerPath = __DIR__ . '/../src/compiler/compiler.php';
if (file_exists($compilerPath)) require_once $compilerPath;

// 2. SQLITE AUTHENTICATION SETUP
$dbPath = __DIR__ . '/users.sqlite';
$db = new SQLite3($dbPath);
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL
)");

$action  = $_GET['action'] ?? '';
$input   = json_decode(file_get_contents('php://input'), true) ?? [];

// --- AUTHENTICATION ROUTES ---
if ($action === 'check_auth') {
    if (isset($_SESSION['username'])) {
        echo json_encode(['status' => 'success', 'username' => $_SESSION['username']]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    }
    exit;
}

if ($action === 'register') {
    $user = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['username'] ?? '');
    $pass = $input['password'] ?? '';
    
    if (strlen($user) < 3 || strlen($pass) < 4) {
        echo json_encode(['status' => 'error', 'message' => 'Username (min 3 chars) and Password (min 4 chars) required.']);
        exit;
    }
    
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password_hash) VALUES (:u, :p)");
    $stmt->bindValue(':u', $user, SQLITE3_TEXT);
    $stmt->bindValue(':p', $hash, SQLITE3_TEXT);
    
    if ($stmt->execute()) {
        $_SESSION['username'] = $user;
        echo json_encode(['status' => 'success', 'username' => $user]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Username already exists.']);
    }
    exit;
}

if ($action === 'login') {
    $user = $input['username'] ?? '';
    $pass = $input['password'] ?? '';
    
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE username = :u");
    $stmt->bindValue(':u', $user, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($row && password_verify($pass, $row['password_hash'])) {
        $_SESSION['username'] = $user;
        echo json_encode(['status' => 'success', 'username' => $user]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid credentials.']);
    }
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['status' => 'success']);
    exit;
}

// --- REQUIRE LOGIN FOR ALL FILE OPERATIONS ---
if (!isset($_SESSION['username'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in.']);
    exit;
}

// 3. USER-SCOPED WORKSPACE
$safeUsername = preg_replace('/[^a-zA-Z0-9_-]/', '', $_SESSION['username']);
define('WORKSPACE_DIR', __DIR__ . '/workspace/' . $safeUsername);

$project = sanitize_path($_GET['project'] ?? 'default');
$file    = sanitize_path($_GET['file'] ?? 'main.hac');

function sanitize_path($path) {
    $path = str_replace(['..', '\\'], ['', '/'], $path);
    return ltrim($path, '/');
}

function get_project_path($project) {
    return WORKSPACE_DIR . '/' . $project;
}

function ensure_directory($dir) {
    if (!is_dir($dir)) mkdir($dir, 0777, true);
}

ensure_directory(WORKSPACE_DIR);
ensure_directory(get_project_path($project));

// --- FILE MANAGER ROUTES ---
switch ($action) {

    case 'list_projects':
        $projects = [];
        if (is_dir(WORKSPACE_DIR)) {
            $items = scandir(WORKSPACE_DIR);
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..' && is_dir(WORKSPACE_DIR . '/' . $item)) {
                    $projects[] = $item;
                }
            }
        }
        if (empty($projects)) {
            $projects[] = 'default';
            ensure_directory(WORKSPACE_DIR . '/default');
        }
        echo json_encode(['status' => 'success', 'projects' => $projects]);
        break;

    case 'create_project':
        $projectPath = get_project_path($project);
        ensure_directory($projectPath);
        
        $defaultFile = $projectPath . '/main.hac';
        if (!file_exists($defaultFile)) {
            file_put_contents($defaultFile, "// Main entry point for {$project}\ntarget x86_64;\n\nnamespace main {\n    function entry() : u32 {\n        return 0;\n    }\n}");
        }

        echo json_encode(['status' => 'success', 'project' => $project, 'message' => "Project '{$project}' created."]);
        break;

    case 'list_files':
        $projectPath = get_project_path($project);
        
        function build_file_tree($dir, $basePath) {
            $tree = [];
            if (!is_dir($dir)) return $tree;
            
            $items = scandir($dir);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..' || $item === 'build') continue;
                
                $fullPath = $dir . '/' . $item;
                $relPath = ltrim($basePath . '/' . $item, '/');
                
                if (is_dir($fullPath)) {
                    $tree[] = [
                        'type' => 'folder',
                        'name' => $item,
                        'path' => $relPath,
                        'children' => build_file_tree($fullPath, $relPath)
                    ];
                } else {
                    $tree[] = [
                        'type' => 'file',
                        'name' => $item,
                        'path' => $relPath
                    ];
                }
            }
            usort($tree, function($a, $b) {
                if ($a['type'] === $b['type']) return strcasecmp($a['name'], $b['name']);
                return $a['type'] === 'folder' ? -1 : 1;
            });
            return $tree;
        }

        $fileTree = build_file_tree($projectPath, '');
        echo json_encode(['status' => 'success', 'files' => $fileTree]);
        break;

    case 'create_folder':
        $folderPath = get_project_path($project) . '/' . $file;
        ensure_directory($folderPath);
        echo json_encode(['status' => 'success', 'message' => "Folder created: {$file}"]);
        break;

    case 'create_file':
        $filePath = get_project_path($project) . '/' . $file;
        ensure_directory(dirname($filePath));

        if (!file_exists($filePath)) {
            file_put_contents($filePath, "// File: {$file}\n");
        }
        echo json_encode(['status' => 'success', 'file' => $file, 'message' => "File created: {$file}"]);
        break;

    case 'load':
        $filePath = get_project_path($project) . '/' . $file;

        if (file_exists($filePath)) {
            echo json_encode(['status'  => 'success', 'file' => $file, 'content' => file_get_contents($filePath)]);
        } else {
            echo json_encode(['status' => 'error', 'message' => "File not found: {$file}"]);
        }
        break;

    case 'save':
        $content = $input['content'] ?? '';
        $filePath = get_project_path($project) . '/' . $file;
        ensure_directory(dirname($filePath));

        if (file_put_contents($filePath, $content) !== false) {
            echo json_encode(['status' => 'success', 'message' => "Saved {$file}"]);
        } else {
            echo json_encode(['status' => 'error', 'message' => "Failed to save {$file}"]);
        }
        break;

    case 'compile_and_fetch':
        $content = $input['content'] ?? '';
        $projectPath = get_project_path($project);
        $filePath    = $projectPath . '/' . $file;

        ensure_directory(dirname($filePath));
        file_put_contents($filePath, $content);

        // --- CLEAN STALE EXECUTABLE BEFORE BUILDING ---
        $rootPathInfo = pathinfo($file);
        $execPath = $projectPath . '/build/' . $rootPathInfo['filename'];
        if (file_exists($execPath)) {
            @unlink($execPath);
        }
        
        $visited = [];     
        $allObjects = [];  
        $lastArtifacts = [];
        $target = \Asm\Core\TargetRegistry::resolve('x86_64');
        $schemaClass = new \Asm\Arch\x86_64();
        $schema = $schemaClass->get_schema();

        // Recursive compilation closure
        $compileUnit = function($relFile, $isRoot = false) use ($target, $schema, $projectPath, &$visited, &$allObjects, &$lastArtifacts, &$compileUnit) {
            $relFile = ltrim(str_replace(['..', '\\'], ['', '/'], $relFile), '/');
            $fullPath = $projectPath . '/' . $relFile;

            if (!file_exists($fullPath)) {
                throw new \Exception("Dependency file not found: {$relFile}");
            }

            $canonicalPath = realpath($fullPath);

            if (isset($visited[$canonicalPath])) {
                if ($visited[$canonicalPath] === 'in_progress') return null;
                return $visited[$canonicalPath];
            }

            $visited[$canonicalPath] = 'in_progress';

            $fileContent = file_get_contents($fullPath);
            $pathInfo = pathinfo($relFile);
            $dir = $pathInfo['dirname'];
            $baseName = $pathInfo['filename'];
            
             // Setup build subdirectory matching directory structure
            $buildSubDir = $projectPath . '/build/' . ($dir === '.' ? '' : $dir);
            if (!is_dir($buildSubDir)) mkdir($buildSubDir, 0777, true);

            // Combine directory and filename into the full namespace, 
            // but keep root files un-namespaced (empty string)
            if ($isRoot || $dir === '.' || $dir === '') {
                $fileNamespace = '';
            } else {
                $fileNamespace = str_replace('/', '.', $dir) . '.' . $baseName;
            }

            // 1. Lexer
            $lexer = new \Asm\Lexer\Lexer($target);
            $lexer->processTokens($fileContent);
            $tokens = $lexer->getTokens();

            // 2. Parser
            $parser = new \Asm\Parser\Parser($target);
            $parser->processTokens($tokens);
            $ast = $parser->getAST();
            $deps = $parser->getDependencies();

            $astArtifact = ['AST' => 'Parsed', 'ast' => $ast, 'dependencies' => $deps];
            $astPath = $buildSubDir . '/' . $baseName . '.ast';
            file_put_contents($astPath, json_encode($astArtifact, JSON_PRETTY_PRINT));

            // --- RECURSIVELY COMPILE DEPENDENCIES ---
            foreach ($deps as $dep) {
                $depRelFile = str_replace('.', '/', $dep['namespace']) . '.hac';
                $compileUnit($depRelFile, false); 
            }

            // 3. Semantic Analysis
            $SA = new \Asm\Semantic\SemanticAnalyzer('x86_64');
            $SA->processAST($ast);
            $at = $SA->get_at();
            $vast = $SA->get_vast();
            $symbolTable = $SA->get_symbol();

            $astValidArtifact = ['status' => 'validated', 'ast' => $vast];
            $symbolArtifact   = ['status' => 'filled', 'symbol' => $symbolTable];

            file_put_contents($buildSubDir . '/' . $baseName . '.ast_valid', json_encode($astValidArtifact, JSON_PRETTY_PRINT));
            file_put_contents($buildSubDir . '/' . $baseName . '.symbol', json_encode($symbolArtifact, JSON_PRETTY_PRINT));

            // 4. Stage 1 Lowering
            $stage1Lowerer = new \Asm\Lowering\Stage1\Stage1Lowerer($target);
            $irProgram = $stage1Lowerer->lower($vast);
            $ir1Artifact = ['status' => 'lowered', 'ir' => $irProgram];

            // 5. Stage 2 Lowering
            $ir1Array = json_decode(json_encode($irProgram, JSON_PRETTY_PRINT), true);
            $stage2Lowerer = new \Asm\Lowering\Stage2\Stage2Lowerer();
            $ir2Artifact = $stage2Lowerer->lower($ir1Array);

            $targetIr = is_array($ir2Artifact) && isset($ir2Artifact['ir']) 
                ? $ir2Artifact['ir'] : ($ir2Artifact instanceof \Asm\IR\IrProgram ? $ir2Artifact : $irProgram);

            // 6. Backend Emitter (Pass $isRoot and $fileNamespace)
            $asmPath = $buildSubDir . '/' . $baseName . '.asm';
            $nasmEmitter = new \Asm\Backend\Nasm\NasmEmitter($at, $isRoot, $fileNamespace);
            $nasmEmitter->emit($targetIr, $asmPath);
            $nasmCode = file_exists($asmPath) ? file_get_contents($asmPath) : '';

            // 7. Assemble (.o)
            $objPath = $buildSubDir . '/' . $baseName . '.o';
            $nasmCommand = "nasm -f elf64 " . escapeshellarg($asmPath) . " -o " . escapeshellarg($objPath) . " 2>&1";
            exec($nasmCommand, $nasmOutput, $nasmExitCode);
            if ($nasmExitCode !== 0) throw new \Exception("Assembler Error in {$relFile}:\n" . implode("\n", $nasmOutput));

            $visited[$canonicalPath] = $objPath;
            $allObjects[] = $objPath;

            if ($isRoot) {
                $lastArtifacts = [
                    'lexer' => $tokens, 'parser' => $astArtifact, 'ast_valid' => $astValidArtifact,
                    'symbol' => $symbolArtifact, 'lowering1' => $ir1Artifact, 'lowering2' => $ir2Artifact, 'nasm' => $nasmCode
                ];
            }

            return $objPath;
        };

        try {
            // Kick off compilation with $isRoot = true for the user's active file
            $compileUnit($file, true);

            // Final Linker Step across ALL collected object files
            $rootPathInfo = pathinfo($file);
            $execPath = $projectPath . '/build/' . $rootPathInfo['filename'];
            ensure_directory(dirname($execPath));

            $uniqueObjects = array_unique($allObjects);
            
            if (empty($uniqueObjects)) {
                throw new \Exception("Linker failed: No object files found.");
            }

            $ldCommand = "ld " . implode(' ', array_map('escapeshellarg', $uniqueObjects)) . " -o " . escapeshellarg($execPath) . " 2>&1";
            exec($ldCommand, $ldOutput, $ldExitCode);
            if ($ldExitCode !== 0) throw new \Exception("Linker Error:\n" . implode("\n", $ldOutput));

            echo json_encode([
                'status'    => 'success',
                'cli_logs'  => "[HAC Recursive Pipeline Executed Successfully]\nCompiled & Linked " . count($uniqueObjects) . " module(s).",
                'errors'    => '0 Errors',
                'artifacts' => $lastArtifacts,
            ]);

        } catch (\Throwable $e) {
            echo json_encode([
                'status'   => 'error',
                'message'  => 'Compilation Error: ' . $e->getMessage(),
                'cli_logs' => "[Compilation Failed]\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getMessage(),
                'errors'   => $e->getMessage(),
            ]);
        }
        break;

     case 'execute':
        $projectPath = get_project_path($project);
        $rootPathInfo = pathinfo($file);
        
        // Locate the compiled binary in the build directory
        $execPath = $projectPath . '/build/' . $rootPathInfo['filename'];

        if (!file_exists($execPath)) {
            echo json_encode([
                'status'  => 'error',
                'message' => "Executable not found at {$execPath}. Please compile your project first."
            ]);
            exit;
        }

        // Grab any stdin input sent from the IDE console UI
        $stdinInput = $input['stdin'] ?? '';

        $descriptors = [
            0 => ['pipe', 'r'], // Stdin pipe
            1 => ['pipe', 'w'], // Stdout pipe
            2 => ['pipe', 'w']  // Stderr pipe
        ];

        // Open the compiled binary process within the project workspace directory
        $process = proc_open(escapeshellarg($execPath), $descriptors, $pipes, $projectPath);

        if (!is_resource($process)) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Failed to start execution process for the compiled binary.'
            ]);
            exit;
        }

        // Write user input to stdin if provided
        if ($stdinInput !== '') {
            fwrite($pipes[0], $stdinInput);
        }
        fclose($pipes[0]);

        // Capture stdout and stderr output
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        // Clean up and retrieve the process exit code
        $exitCode = proc_close($process);

        echo json_encode([
            'status'     => 'success',
            'stdout'     => $stdout,
            'stderr'     => $stderr,
            'exit_code'  => $exitCode
        ]);
        break;
        
        default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}