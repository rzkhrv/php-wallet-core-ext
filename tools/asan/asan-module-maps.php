<?php
// ASan/LSan may report "<unknown module>" after PHP dlcloses walletcore.so.
// Record executable ELF mappings while the extension is loaded. A separate
// post-failure script can translate leaked instruction pointers to ELF offsets
// and symbolize them against the unstripped .so before Docker exits.
$asanMapLines = @file('/proc/self/maps', FILE_IGNORE_NEW_LINES);
if (is_array($asanMapLines)) {
    foreach ($asanMapLines as $asanMapLine) {
        if (preg_match('/^[0-9a-f]+-[0-9a-f]+\s+r-xp\s+/', $asanMapLine)
            && strpos($asanMapLine, '/') !== false) {
            fwrite(STDERR, "WC_ASAN_MAP " . $asanMapLine . "\n");
        }
    }
} else {
    fwrite(STDERR, "WC_ASAN_MAP_ERROR cannot read /proc/self/maps\n");
}
unset($asanMapLines, $asanMapLine);
