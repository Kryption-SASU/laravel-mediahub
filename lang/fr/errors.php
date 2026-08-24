<?php

declare(strict_types=1);

/*
 * Les refus qu'une personne peut voir, et ce qu'on lui dit.
 *
 * ⚠️ Le paquet lève des CLÉS, pas des phrases : ces lignes ne sont qu'un rendu par défaut.
 * Un hôte qui veut sa propre formulation publie ce fichier et le modifie.
 *
 * ⚠️ Les échecs de configuration n'y figurent pas : personne d'autre que celui qui installe
 * le paquet ne les lira, et les traduire ne ferait que rendre leur recherche plus difficile.
 */

return [

    // ── Dépôts ──────────────────────────────────────────────────────────────

    'too_large' => 'Ce fichier est trop volumineux.',
    'extension_not_allowed' => 'Ce type de fichier n’est pas accepté.',

    'extension_mismatch' => 'Le contenu de ce fichier ne correspond pas à son extension.',
    'svg_not_allowed' => 'Les fichiers SVG ne sont pas acceptés.',

    'unreadable' => 'Ce fichier n’a pas pu être lu.',
    'source_unreadable' => 'Le fichier d’origine n’a pas pu être lu.',
    'mime_unreadable' => 'Le type de ce fichier n’a pas pu être déterminé.',
    'not_inspectable' => 'Ce fichier n’a pas pu être examiné avant d’être enregistré.',

    'image_unreadable' => 'Cette image n’a pas pu être lue.',
    'image_too_large' => 'Cette image est trop grande pour être traitée.',

    'quota_exceeded' => 'Il ne reste plus assez d’espace.',

    // ── Opérations sur la médiathèque ───────────────────────────────────────

    'item_not_found' => 'Cet élément n’existe plus.',
    'selection_empty' => 'Aucun élément n’a été sélectionné.',

    'media_name_required' => 'Un nom est requis.',
    'folder_name_required' => 'Un nom de dossier est requis.',

    // ── Le rattachement à un modèle de l'hôte ───────────────────────────────

    /*
     * ⚠️ « ICI », PAS « DANS CETTE COLLECTION ». Une collection est un mot du code ; la personne
     * qui lit ceci a choisi un fichier sur un écran qui dit « couverture » ou « pièces
     * jointes », et lui parler de collections ne lui apprend rien d'actionnable.
     */
    'collection_type_rejected' => "Ce type de fichier n'est pas accepté ici.",
    'collection_file_too_large' => 'Ce fichier est trop volumineux pour cet emplacement.',

    'folder_cycle' => 'Un dossier ne peut pas être déplacé dans lui-même.',
    'folder_too_deep' => 'Cette arborescence est trop profonde.',

    // ── Archives ────────────────────────────────────────────────────────────

    'archive_empty' => 'Il n’y a rien à télécharger.',
    'archive_too_many_files' => 'Trop de fichiers ont été sélectionnés pour un seul téléchargement.',
    'archive_too_large' => 'Cette sélection est trop volumineuse pour être téléchargée en une fois.',

];
