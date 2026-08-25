<?php

declare(strict_types=1);

/*
 * Les mots que le paquet écrit dans les données elles-mêmes.
 *
 * ⚠️ CE FICHIER N'EST PAS COMME `errors.php`, ET LA DIFFÉRENCE COMPTE. Un refus est rendu au
 * moment où quelqu'un le lit, donc le traduire plus tard fonctionne encore. Le nom d'une copie
 * est ENREGISTRÉ : il est choisi une fois, écrit dans la ligne, puis relu par tous les écrans et
 * tous les exports. Modifier ce fichier ne renomme rien de ce qui existe déjà.
 */

return [

    /*
     * ⚠️ À LA FIN PLUTÔT QU'AU DÉBUT. Une bibliothèque triée par nom garde la copie à côté de ce
     * qu'elle copie ; un préfixe rassemblerait toutes les copies jamais faites au même endroit
     * de la liste.
     */
    'copy' => ':name (copie)',

    'copy_numbered' => ':name (copie :number)',

];
