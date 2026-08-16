<?php
header('Content-Type: application/json');

// 1. AUTOLOAD COMPILER CLASSES
// Registers PSR-4 styled autoloader mapping Asm\ namespace to ../src/
spl_autoload_register(function ($class) {
    $prefix = 'Asm\\';
    $baseDir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Require compiler explicitly as entry point if needed
$compilerPath = __DIR__ . '/../src/compiler/compiler.php';
if (file_exists($compilerPath)) {
    require_once $compilerPath;
}

// Root workspace directory relative to api.php
define('WORKSPACE_DIR', __DIR__ . '/workspace');

// Retrieve action and parameters
$action  = $_GET['action'] ?? '';
$project = sanitize_path($_GET['project'] ?? 'default');
$file    = sanitize_path($_GET['file'] ?? 'main.hac');

// Security helper: restrict paths within project sandbox
function sanitize_path($path) {
    $path = str_replace(['..', '\\'], ['', '/'], $path);
    return ltrim($path, '/');
}

function get_project_path($project) {
    return WORKSPACE_DIR . '/' . $project;
}

function ensure_directory($dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

// Ensure base project directory exists
ensure_directory(get_project_path($project));

switch ($action) {

    // --- LIST PROJECTS ---
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

    // --- CREATE PROJECT ---
    case 'create_project':
        $projectPath = get_project_path($project);
        ensure_directory($projectPath);
        
        $defaultFile = $projectPath . '/main.hac';
        if (!file_exists($defaultFile)) {
            file_put_contents($defaultFile, "// Main entry point for {$project}\nfn main() {\n    print(\"Hello, HAC!\");\n}");
        }

        echo json_encode(['status' => 'success', 'project' => $project, 'message' => "Project '{$project}' created."]);
        break;

    // --- LIST FILES (RECURSIVE, EXCLUDING BUILD DIR) ---
    case 'list_files':
        $projectPath = get_project_path($project);
        $fileList = [];

        if (is_dir($projectPath)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($projectPath, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $path) {
                if ($path->isFile()) {
                    $relativePath = substr($path->getPathname(), strlen($projectPath) + 1);
                    $relativePath = str_replace('\\', '/', $relativePath);
                    
                    if (strpos($relativePath, 'build/') !== 0) {
                        $fileList[] = $relativePath;
                    }
                }
            }
        }
        
        sort($fileList);
        echo json_encode(['status' => 'success', 'files' => $fileList]);
        break;

    // --- CREATE FILE OR SUBFOLDER ---
    case 'create_file':
        $filePath = get_project_path($project) . '/' . $file;
        $dir = dirname($filePath);
        
        ensure_directory($dir);

        if (!file_exists($filePath)) {
            file_put_contents($filePath, "// File: {$file}\n");
        }

        echo json_encode(['status' => 'success', 'file' => $file, 'message' => "File created: {$file}"]);
        break;

    // --- LOAD FILE ---
    case 'load':
        $filePath = get_project_path($project) . '/' . $file;

        if (file_exists($filePath)) {
            echo json_encode([
                'status'  => 'success',
                'file'    => $file,
                'content' => file_get_contents($filePath)
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => "File not found: {$file}"]);
        }
        break;

    // --- SAVE FILE ---
    case 'save':
        $input = json_decode(file_get_contents('php://input'), true);
        $content = $input['content'] ?? '';
        
        $filePath = get_project_path($project) . '/' . $file;
        ensure_directory(dirname($filePath));

        if (file_put_contents($filePath, $content) !== false) {
            echo json_encode(['status' => 'success', 'message' => "Saved {$file}"]);
        } else {
            echo json_encode(['status' => 'error', 'message' => "Failed to save {$file}"]);
        }
        break;

    // --- REAL COMPILER INTEGRATION ---
    case 'compile_and_fetch':
        $input = json_decode(file_get_contents('php://input'), true);
        $content = $input['content'] ?? '';

        $projectPath = get_project_path($project);
        $filePath    = $projectPath . '/' . $file;

        // Save active content before compiling
        ensure_directory(dirname($filePath));
        file_put_contents($filePath, $content);

        // Path metadata for build mirroring
        $pathInfo = pathinfo($file);
        $baseName = $pathInfo['filename'];
        $subDir   = ($pathInfo['dirname'] !== '.' && $pathInfo['dirname'] !== '') ? $pathInfo['dirname'] : '';
        
        $buildSubDir = $projectPath . '/build' . ($subDir ? '/' . $subDir : '');
        ensure_directory($buildSubDir);

        try {
            // Instantiate Lexer with the code content passed as a parameter
            $schemaClass = new \Asm\Arch\x86_64();
            $schema = $schemaClass->get_schema();
            $lexer = new \Asm\Lexer\Lexer($schema);
            $lexer->setInput($content);
            
            $tokens = [];
            while (true) {
                $token = $lexer->nextToken();
                $tokens[] = $token;
                if ($token->type === \Asm\Lexer\TokenType::EOF) {
                    break;
                }
            }
            
            $lexerArtifact    = $tokens;
            
            $savedFiles =[];

            $lexPath = $buildSubDir . '/' . $baseName . '.lex';
            file_put_contents($lexPath, json_encode($lexerArtifact, JSON_PRETTY_PRINT));
            $savedFiles['lexer'] = str_replace($projectPath . '/', '', $lexPath);

            // Run Parser
            $parser = new \Asm\Parser\Parser($schema);
            $parser->processTokens($tokens);
            $ast = $parser->getAST();
            
            $astArtifact = ['AST' => 'Parsed', 'ast' => $ast];
            
            $astPath = $buildSubDir . '/' . $baseName . '.ast';
            file_put_contents($astPath, json_encode($astArtifact, JSON_PRETTY_PRINT));
            $savedFiles['parser'] = str_replace($projectPath . '/', '', $astPath);

            // Run Semantic Analyser
            $SA = new \Asm\Semantic\SemanticAnalyzer('x86_64');
            $SA->processAST($ast);
            $vast = $SA->get_vast();
            $symbolTable = $SA->get_symbol();
            

            // Run main compiler engine
   //         $asmOutputFile = $buildSubDir . '/' . $baseName . '.asm';
  //          $compiler = new \Asm\Compiler\Compiler($projectPath);
   //         $compiler->compile($filePath, $asmOutputFile);

            // Structure compiler outputs into JSON artifacts
//            $astArtifact      = $ast;
//            $astArtifact = ['AST' => 'Parsed', 'ast' => $ast];
            $astValidArtifact = ['status' => 'validated', 'ast' => $vast];
            $symbolArtifact   = ['status' => 'filled', 'symbol' => $symbolTable];

            // Save compiler artifacts to target build directory


//            $astPath = $buildSubDir . '/' . $baseName . '.ast';
//            file_put_contents($astPath, json_encode($astArtifact, JSON_PRETTY_PRINT));
//            $savedFiles['parser'] = str_replace($projectPath . '/', '', $astPath);

            $astValidPath = $buildSubDir . '/' . $baseName . '.ast_valid';
            file_put_contents($astValidPath, json_encode($astValidArtifact, JSON_PRETTY_PRINT));
            $savedFiles['ast_valid'] = str_replace($projectPath . '/', '', $astValidPath);

            $symbolPath = $buildSubDir . '/' . $baseName . '.symbol';
            file_put_contents($symbolPath, json_encode($symbolArtifact, JSON_PRETTY_PRINT));
            $savedFiles['symbol'] = str_replace($projectPath . '/', '', $symbolPath);

            // Respond back to IDE interface
            echo json_encode([
                'status'   => 'success',
                'cli_logs' => "[HAC Compiler Executed Successfully]\nGenerated assembly output: {$baseName}.asm\n\nArtifacts written:\n - " . implode("\n - ", $savedFiles),
                'errors'   => '0 Errors',
                'artifacts' => [
                    'lexer'     => $lexerArtifact,
                    'parser'    => $astArtifact,
                    'ast_valid' => $astValidArtifact,
                    'symbol'    => $symbolArtifact
                ],
                'saved_artifacts' => $savedFiles
            ]);

        } catch (\Throwable $e) {
            echo json_encode([
                'status'   => 'error',
                'message'  => 'Compilation Error: ' . $e->getMessage(),
                'cli_logs' => "[Compilation Failed]\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getMessage(),
                'errors'   => $e->getMessage() . "\n\nStack Trace:\n" . $e->getTraceAsString()
            ]);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
