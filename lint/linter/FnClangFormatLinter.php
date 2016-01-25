<?php

/**
 * Uses the clang format to format C/C++/Obj-C code
 */
final class FnClangFormatLinter extends ArcanistExternalLinter {

  private $style;

  public function getInfoName() {
    return 'clang-format';
  }

  public function getInfoURI() {
    return 'http://clang.llvm.org/docs/ClangFormat.html';
  }

  public function getInfoDescription() {
    return pht(
      'A tool to format C/C++/Java/JavaScript/Objective-C/Protobuf code.');
  }

  public function getLinterName() {
    return 'CLANGFORMAT';
  }

  public function getLinterConfigurationName() {
    return 'clang-format';
  }

  public function getDefaultBinary() {
    return 'clang-format';
  }

  public function getVersion() {
    list($stdout) = execx('%C --version', $this->getExecutableCommand());

    $matches = array();
    $regex = '/^clang-format version (?P<version>\S+)/';
    if (preg_match($regex, $stdout, $matches)) {
      return $matches['version'];
    } else {
      return false;
    }
  }

  public function getInstallInstructions() {
    return pht('See http://llvm.org/releases/download.html');
  }

  public function shouldExpectCommandErrors() {
    return false;
  }

  protected function getMandatoryFlags() {
    $options = array();
    $options[] = sprintf('--style=%s', coalesce($this->style, 'file'));
    return $options;
  }

  public function getLinterConfigurationOptions() {
    $options = array(
      'clang-format.style' => array(
        'type' => 'optional string',
        'help' => pht(
          'Either "file" (to use a .clang-format file in a parent directory '.
          'of the file being checked), a clang-format predefined style, or a '.
          'JSON dictionary of style options. See the docs.'),
      ),
    );

    return $options + parent::getLinterConfigurationOptions();
  }

  public function setLinterConfigurationValue($key, $value) {
    switch ($key) {
      case 'clang-format.style':
        $this->style = $value;
        return $this;
    }

    return parent::setLinterConfigurationValue($key, $value);
  }

  protected function getPathArgumentForLinterFuture($path) {
    $full_path = Filesystem::resolvePath($path);
    $ret = array($full_path);

    // The |path| we get fed needs to be made relative to the project_root,
    // otherwise the |engine| won't recognise it.
    $relative_path = Filesystem::readablePath(
      $full_path, $this->getProjectRoot());
    $changed = $this->getEngine()->getPathChangedLines($relative_path);

    if ($changed !== null && count(array_filter($changed)) > 0) {
      // Convert the ordered set of changed lines to a list of ranges.
      $changed_lines = array_keys(array_filter($changed));
      $ranges = array(
        array($changed_lines[0], $changed_lines[0]),
      );

      foreach (array_slice($changed_lines, 1) as $line) {
        $range = last($ranges);
        if ($range[1] + 1 === $line) {
          ++$range[1];
          $ranges[last_key($ranges)] = $range;
        } else {
          $ranges[] = array($line, $line);
        }
      }

      foreach ($ranges as $range) {
        $ret[] = sprintf('--lines=%d:%d', $range[0], $range[1]);
      }
    }
    return csprintf('%Ls', $ret);
  }

  protected function parseLinterOutput($path, $err, $stdout, $stderr) {
    $old_lines = phutil_split_lines($this->getData($path));
    $new_lines = phutil_split_lines($stdout);
    $op_codes = id(new FnSequenceMatcher($old_lines, $new_lines))->getOpCodes();

    $messages = array();
    foreach ($op_codes as $op_code) {
      list($_op, $i1, $i2, $j1, $j2) = $op_code;

      $messages[] = id(new ArcanistLintMessage())
        ->setBypassChangedLineFiltering(true)
        ->setPath($path)
        ->setCode($this->getLinterName())
        ->setSeverity(ArcanistLintSeverity::SEVERITY_AUTOFIX)
        ->setName('Formatting suggestion')
        ->setDescription(pht('%s suggests an alternative formatting.',
                             $this->getInfoName()))
        ->setLine($i1 + 1)
        ->setChar(1)
        ->setOriginalText(
          implode('', array_slice($old_lines, $i1, $i2 - $i1)))
        ->setReplacementText(
          implode('', array_slice($new_lines, $j1, $j2 - $j1)));
    }
    return $messages;
  }
}