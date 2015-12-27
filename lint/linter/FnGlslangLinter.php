<?php

/**
 * Uses Glslang to verify GLSL shaders.
 */
final class FnGlslangLinter extends ArcanistExternalLinter {

  public function getInfoName() {
    return 'GLSL Validator';
  }

  public function getInfoURI() {
    return 'https://github.com/KhronosGroup/glslang';
  }

  public function getInfoDescription() {
    return pht('GLSL validator from Khronos Group');
  }

  public function getLinterName() {
    return 'GLSLANG';
  }

  public function getLinterConfigurationName() {
    return 'fn-glslang';
  }

  public function getDefaultBinary() {
    return 'glslangValidator';
  }

  public function getInstallInstructions() {
    return pht('Install CMake and Bison. Then see: '.
               'https://github.com/KhronosGroup/glslang');
  }

  protected function parseLinterOutput($path, $err, $stdout, $stderr) {
    $lines = phutil_split_lines($stdout);
    $messages = array();

    foreach ($lines as $line) {
      $matches = null;
      if (!preg_match('/^(\w+): \d+:(\d+): (.*)$/', $line, $matches)) {
        continue;
      }

      $message = id(new ArcanistLintMessage())
        ->setPath($path)
        ->setLine($matches[2])
        ->setCode($this->getLinterName())
        ->setDescription($matches[3]);

      if ($matches[1] === 'ERROR') {
        $message->setSeverity(ArcanistLintSeverity::SEVERITY_ERROR)
        ->setName('Syntax error');
      } else {
        $message->setSeverity(ArcanistLintSeverity::SEVERITY_WARNING)
        ->setName('Glslang Warning');
      }
      $messages[] = $message;
    }

    return $messages;
  }
}
