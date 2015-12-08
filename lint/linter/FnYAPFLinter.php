<?php

/**
 * Uses YAPF to format Python code.
 */
final class FnYAPFLinter extends ArcanistExternalLinter {

  private $style;

  public function getInfoName() {
    return 'YAPF';
  }

  public function getInfoURI() {
    return 'https://github.com/google/yapf';
  }

  public function getInfoDescription() {
    return pht('Formatter for Python code');
  }

  public function getLinterName() {
    return 'YAPF';
  }

  public function getLinterConfigurationName() {
    return 'fn-yapf';
  }

  public function getDefaultBinary() {
    return 'yapf';
  }

  public function getVersion() {
    list($stdout) = execx('%C --version', $this->getExecutableCommand());

    $matches = array();
    $regex = '/^yapf (?P<version>\S+)/';
    if (preg_match($regex, $stdout, $matches)) {
      return $matches['version'];
    } else {
      return false;
    }
  }

  public function getInstallInstructions() {
    return pht('pip install yapf');
  }

  public function shouldExpectCommandErrors() {
    return true;
  }

  protected function getMandatoryFlags() {
    $options = array();
    $options[] = sprintf('--style=%s', coalesce($this->style, 'pep8'));
    return $options;
  }

  public function getLinterConfigurationOptions() {
    $options = array(
      'yapf.style' => array(
        'type' => 'optional string',
        'help' => pht(
          'specify formatting style: either a style name (for example "pep8" '.
          'or "google"), or the name of a file with style settings. The '.
          'default is pep8 unless a .style.yapf or setup.cfg file located in '.
          'one of the parent directories of the source file'),
      ),
    );

    return $options + parent::getLinterConfigurationOptions();
  }

  public function setLinterConfigurationValue($key, $value) {
    switch ($key) {
      case 'yapf.style':
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

    if ($changed !== null) {
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
        $ret[] = sprintf('--lines=%d-%d', $range[0], $range[1]);
      }
    }
    return csprintf('%Ls', $ret);
  }

  protected function parseLinterOutput($path, $err, $stdout, $stderr) {
    if ($err !== 2) {
      return array();
    }

    $old_lines = phutil_split_lines($this->getData($path));
    $new_lines = phutil_split_lines($stdout);
    $op_codes = id(new FnSequenceMatcher($old_lines, $new_lines))->getOpCodes();

    $messages = array();
    foreach ($op_codes as $op_code) {
      list($op, $i1, $i2, $j1, $j2) = $op_code;
      $li = $i2 - $i1;
      $lj = $j2 - $j1;

      $message = id(new ArcanistLintMessage())
        ->setPath($path)
        ->setCode($this->getLinterName())
        ->setSeverity(ArcanistLintSeverity::SEVERITY_AUTOFIX)
        ->setName('Formatting suggestion')
        ->setDescription(pht('%s suggestes an alternative formatting.',
                             $this->getLinterName()))
        ->setLine($i1 + 1)
        ->setChar(1)
        ->setOriginalText(
          implode('', array_slice($old_lines, $i1, $li)))
        ->setReplacementText(
          implode('', array_slice($new_lines, $j1, $lj)));

      // To work around an issue in YAPF where whitespace reformatting occurrs
      // outside the requested lines, try to determine if this hunk only
      // affects leading or trailing whitespace. If so, then we allow arcanist
      // to ignore it.
      $is_whitespace_only = true;
      for ($k = 0; $is_whitespace_only && $k < max($li, $lj); ++$k) {
        $line_a = $k < $li ? $old_lines[$i1 + $k] : '';
        $line_b = $k < $lj ? $new_lines[$j1 + $k] : '';
        $is_whitespace_only = trim($line_a) === trim($line_b);
      }
      $message->setBypassChangedLineFiltering(!$is_whitespace_only);

      $messages[] = $message;
    }
    return $messages;
  }
}
