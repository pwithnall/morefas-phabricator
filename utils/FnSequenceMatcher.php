<?php

// Poor man's replacement for Python's difflib.SequenceMatcher().

final class FnSequenceMatcher extends Phobject {

  private $a;
  private $b;

  public function __construct(array $a, array $b) {
    $this->a = $a;
    $this->b = $b;
  }

  public function getOpCodes() {
    $op_codes = array();

    $file_a = new TempFile();
    $file_b = new TempFile();

    Filesystem::writeFile($file_a, implode('', $this->a));
    Filesystem::writeFile($file_b, implode('', $this->b));

    $diffFuture = new ExecFuture('diff -U0 %s %s', $file_a, $file_b);
    list($err, $stdout, $stderr) = $diffFuture->resolve();
    if (!in_array($err, array(0, 1))) {
      throw new CommandException(
        pht('`diff` returned unexpected exit code %d', $err),
        $diffFuture->getCommand(),
        $err,
        $stdout,
        $stderr);
    }

    foreach (phutil_split_lines($stdout) as $line) {
      $matches = null;
      $regexp = '/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/';
      if (!preg_match($regexp, $line, $matches)) {
        continue;
      }

      // We need to:
      // - normalize indices; the ones we get from `diff` are 1-indexed.
      // - account for how `diff` represents empty hunks -- essentially, it
      //   attributes the change to the *previous line* (which may be 0!). This
      //   differs from Python's difflib.
      //
      // http://www.gnu.org/software/diffutils/manual/html_node/Detailed-Unified.html:
      //     If a hunk contains just one line, only its start line number
      //     appears.  Otherwise its line numbers look like ‘start,count’. An
      //     empty hunk is considered to start at the line that follows the
      //     hunk.
      //
      //     If a hunk and its context contain two or more lines, its line
      //     numbers look like ‘start,count’. Otherwise only its end line
      //     number appears.  An empty hunk is considered to end at the line
      //     that precedes the hunk.

      $i = (int)$matches[1] - 1;
      $j = (int)$matches[3] - 1;
      if (isset($matches[2]) && $matches[2] !== '') {
        $li = (int)$matches[2];
      } else {
        $li = 1;
      }
      if (isset($matches[4]) && $matches[4] !== '') {
        $lj = (int)$matches[4];
      } else {
        $lj = 1;
      }

      if (!($li || $lj)) {
        throw new CommandException(
          pht('Malformed output from `diff`.'),
          $diffFuture->getCommand(),
          $err,
          $stdout,
          $stderr);
      }

      if ($li === 0) {
        $op_codes[] = array('insert', $i + 1, $i + 1, $j, $j + $lj);
      } elseif ($lj === 0) {
        $op_codes[] = array('delete', $i, $i + $li, $j, $j);
      } else {
        $op_codes[] = array('replace', $i, $i + $li, $j, $j + $lj);
      }
    }
    return $op_codes;
  }
}
