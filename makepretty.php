<?php
require_once("PVPretty.php");

//|
//|   Takes a PHP file and runs it through the pretty printer.
//|   Removes the original file but makes a copy in the /tmp/ folder

function MakePretty($filename)
{
    $backupNum = 0;
    while (true)
    {
        $backupFile = "/tmp/" . basename($filename) . ".backup{$backupNum}";
        if (@file_exists($backupFile))
        {
            $backupNum++;
        } else {
            break;
        }
    }

    $code = file_get_contents($filename);
    $code = str_replace("\\n", "\\\\n", $code);
    $code = str_replace("\\t", "\\\\t", $code);
    $code = str_replace("\\s", "\\\\s", $code);
    $code = str_replace("\\d", "\\\\d", $code);

    $code = str_replace("\r\n", "\n", $code);
    $code = str_replace("\r", "\n", $code);
    file_put_contents($backupFile, $code);

    $result = ReformatPHP($code);
    file_put_contents($filename, $result);
}

for ($x=1; isset($argv[$x]); $x++)
{
    $filename = $argv[$x];
    MakePretty($filename);
    print "Finished with $filename\n";
}
