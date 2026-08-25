<?php

declare(strict_types=1);

/*
 * Wording the package writes into the data itself.
 *
 * ⚠️ THIS FILE IS NOT LIKE `errors.php`, AND THE DIFFERENCE MATTERS. A refusal is rendered at
 * the moment somebody reads it, so translating it later still works. A copy's name is STORED:
 * it is chosen once, written to the row, and read by every screen and every export afterwards.
 * Changing this file renames nothing that already exists.
 *
 * ⚠️ WHICH IS ALSO WHY IT IS TRANSLATED AT ALL. A French library whose copies are all called
 * "(copy)" is a library that had an English package installed in it, and the only way to fix
 * one of those names is to rename the file by hand.
 */

return [

    /*
     * ⚠️ THE COPY IS MARKED, OR IT IS LOST. Duplicating gave the new row the same displayed name
     * as the original — two identical tiles, side by side, with nothing to tell them apart and
     * no way to know which one anybody had since edited. The file name on disk was already made
     * unique; the name people read was not.
     *
     * ⚠️ AT THE END RATHER THAN THE FRONT. A library sorted by name keeps a copy next to what it
     * was copied from; a prefix moves every copy ever made into the same place in the list,
     * under "c".
     */
    'copy' => ':name (copy)',

    /*
     * ⚠️ AND A SECOND COPY IS NUMBERED RATHER THAN DOUBLY SUFFIXED. Duplicating twice would
     * otherwise give "photo (copy) (copy)", and a fourth time a name nobody can read.
     */
    'copy_numbered' => ':name (copy :number)',

];
