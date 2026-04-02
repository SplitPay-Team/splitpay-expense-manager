<?php
class Template {
    private $templateDir;
    private $variables = [];
    
    public function __construct($templateDir = 'templates/') {
        $this->templateDir = rtrim($templateDir, '/') . '/';
    }
    
    public function assign($key, $value) {
        $this->variables[$key] = $value;
    }
    
    public function render($templateFile) {
        if (strpos($templateFile, '<') !== false) {
            return $this->renderString($templateFile);
        }
        
        $templatePath = $this->templateDir . $templateFile;
        
        if (!file_exists($templatePath)) {
            if (!preg_match('/\.html$/', $templatePath)) {
                $templatePath .= '.html';
            }
            
            if (!file_exists($templatePath)) {
                die("Template not found: " . $templateFile);
            }
        }
        
        $content = file_get_contents($templatePath);
        return $this->renderString($content);
    }
    
    private function renderString($content) {
        // FIRST: Process loops (this creates the repeated content and handles nested ifs)
        $content = $this->processLoops($content);
        
        // SECOND: Replace any remaining single variables
        foreach ($this->variables as $key => $value) {
            if (!is_array($value)) {
                $content = str_replace('{{' . $key . '}}', htmlspecialchars($value), $content);
            }
        }
        
        // THIRD: Process top-level if statements (not inside loops)
        $content = $this->processTopLevelIfs($content);
        
        // Remove any remaining variables
        $content = preg_replace('/{{[^}]+}}/', '', $content);
        
        return $content;
    }

    private function processTopLevelIfs($content) {
        // Process if statements with else
        preg_match_all('/{{#if (\w+)}}(.*?){{else}}(.*?){{\/if}}/s', $content, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $condition = $match[1];
            $ifContent = $match[2];
            $elseContent = $match[3];
            
            $conditionValue = isset($this->variables[$condition]) && !empty($this->variables[$condition]);
            $replacement = $conditionValue ? $ifContent : $elseContent;
            $content = str_replace($match[0], $replacement, $content);
        }
        
        // Process if statements without else
        preg_match_all('/{{#if (\w+)}}(.*?){{\/if}}/s', $content, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $condition = $match[1];
            $ifContent = $match[2];
            
            $conditionValue = isset($this->variables[$condition]) && !empty($this->variables[$condition]);
            $replacement = $conditionValue ? $ifContent : '';
            $content = str_replace($match[0], $replacement, $content);
        }
        
        return $content;
    }

    private function processLoops($content) {
        preg_match_all('/{{#each (\w+)}}(.*?){{\/each}}/s', $content, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $loopVar = $match[1];
            $loopTemplate = $match[2];
            $loopHtml = '';
            
            if (isset($this->variables[$loopVar]) && is_array($this->variables[$loopVar])) {
                foreach ($this->variables[$loopVar] as $item) {
                    $itemHtml = $loopTemplate;
                    
                    // First, replace all the simple variables in this item
                    foreach ($item as $key => $value) {
                        $itemHtml = str_replace('{{' . $key . '}}', $value, $itemHtml);
                    }
                    
                    // Then, process any if statements within this item
                    // This is the key fix - process nested ifs immediately
                    $itemHtml = $this->processNestedIfs($itemHtml, $item);
                    
                    $loopHtml .= $itemHtml;
                }
            }
            
            $content = str_replace($match[0], $loopHtml, $content);
        }
        
        return $content;
    }

    private function processNestedIfs($content, $context) {
        // Process if statements with else
        preg_match_all('/{{#if (\w+)}}(.*?){{else}}(.*?){{\/if}}/s', $content, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $condition = $match[1];
            $ifContent = $match[2];
            $elseContent = $match[3];
            
            // Check the condition using the item's context
            $conditionValue = isset($context[$condition]) && !empty($context[$condition]);
            
            $replacement = $conditionValue ? $ifContent : $elseContent;
            $content = str_replace($match[0], $replacement, $content);
        }
        
        // Process if statements without else
        preg_match_all('/{{#if (\w+)}}(.*?){{\/if}}/s', $content, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $condition = $match[1];
            $ifContent = $match[2];
            
            $conditionValue = isset($context[$condition]) && !empty($context[$condition]);
            
            $replacement = $conditionValue ? $ifContent : '';
            $content = str_replace($match[0], $replacement, $content);
        }
        
        return $content;
    }

    private function processIfStatements($content) {
        // Process if statements repeatedly until no more changes
        $maxIterations = 10;
        $iteration = 0;
        
        while ($iteration < $maxIterations) {
            $newContent = $content;
            
            // Handle if statements with else
            preg_match_all('/{{#if (\w+)}}(.*?){{else}}(.*?){{\/if}}/s', $newContent, $matches, PREG_SET_ORDER);
            
            foreach ($matches as $match) {
                $condition = $match[1];
                $ifContent = $match[2];
                $elseContent = $match[3];
                
                // Check if this variable exists and is truthy
                $conditionValue = false;
                if (isset($this->variables[$condition])) {
                    $value = $this->variables[$condition];
                    if (is_bool($value)) {
                        $conditionValue = $value;
                    } else if (is_array($value)) {
                        $conditionValue = count($value) > 0;
                    } else {
                        $conditionValue = !empty($value);
                    }
                }
                
                $replacement = $conditionValue ? $ifContent : $elseContent;
                $newContent = str_replace($match[0], $replacement, $newContent);
            }
            
            // Handle if statements without else
            preg_match_all('/{{#if (\w+)}}(.*?){{\/if}}/s', $newContent, $matches, PREG_SET_ORDER);
            
            foreach ($matches as $match) {
                $condition = $match[1];
                $ifContent = $match[2];
                
                $conditionValue = false;
                if (isset($this->variables[$condition])) {
                    $value = $this->variables[$condition];
                    if (is_bool($value)) {
                        $conditionValue = $value;
                    } else if (is_array($value)) {
                        $conditionValue = count($value) > 0;
                    } else {
                        $conditionValue = !empty($value);
                    }
                }
                
                $replacement = $conditionValue ? $ifContent : '';
                $newContent = str_replace($match[0], $replacement, $newContent);
            }
            
            if ($newContent === $content) {
                break;
            }
            
            $content = $newContent;
            $iteration++;
        }
        
        return $content;
    }
    
    public function renderWithHeaderFooter($contentFile, $headerFile = 'header.html', $footerFile = 'footer.html') {
        $header = $this->render($headerFile);
        $content = $this->render($contentFile);
        $footer = $this->render($footerFile);
        
        return $header . $content . $footer;
    }
    
    public function renderWithHeaderFooterContent($content, $headerFile = 'header.html', $footerFile = 'footer.html') {
        $header = $this->render($headerFile);
        $footer = $this->render($footerFile);
        
        return $header . $content . $footer;
    }
}
?>
