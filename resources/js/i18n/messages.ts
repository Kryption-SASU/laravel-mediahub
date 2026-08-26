/*
 * EVERY WORD THIS PACKAGE PUTS ON A SCREEN.
 *
 * ⚠️ THE MARKUP IS FROZEN, SO TRANSLATION IS THE ONLY WAY TO CHANGE A LABEL. That makes this
 * file part of the contract rather than a convenience: a key that is missing here is a word
 * nobody can change, and the only remaining move is to fork the component — which is precisely
 * what the frozen markup was meant to make unnecessary. The set has to be complete and fine
 * grained from the first version, one key per button, per empty state, per message.
 *
 * ⚠️ AND IT IS DATA, NOT MARKUP. A headless host, or one rendering from something other than
 * Vue, consumes these objects directly; strings concatenated into a template would be reachable
 * only from Vue.
 */

/** A message, or two forms separated by a pipe for the singular and the plural. */
export type MhMessages = Record<string, string>

export interface MhLocale {
    messages: MhMessages
    /**
     * ⚠️ WHICH FORM A COUNT TAKES IS A PROPERTY OF THE LANGUAGE, NOT OF THE MESSAGE. English
     * puts zero in the plural — "0 files" — and French puts it in the singular — "0 fichier".
     * A single shared rule gets one of the two wrong on every screen that counts anything.
     */
    plural(count: number): number
}

const en: MhMessages = {
    'actions.preview': 'Preview',
    'actions.link': 'Copy link',
    'actions.rename': 'Rename',
    'actions.duplicate': 'Duplicate',
    'actions.regenerate': 'Rebuild the thumbnail',
    'actions.download': 'Download',
    'actions.archive': 'Download as ZIP',
    'actions.trash': 'Move to trash',
    'actions.trash.confirmTitle': 'Move to the trash?',
    'actions.trash.confirmMessage': 'They can be restored from the trash afterwards.',
    /* ⚠️ COUNTED, SO PLURALISED — and the folders are not counted here, the files are: it is
       the files somebody did not tick that they need to be told about. */
    'actions.trash.confirmInside':
        '{count} file inside will go with them, and can be restored from the trash afterwards.|{count} files inside will go with them, and can be restored from the trash afterwards.',
    'actions.restore': 'Restore',
    'rename.title': 'Rename',
    'rename.field': 'Name',
    'rename.save': 'Rename',
    'rename.cancel': 'Cancel',
    'viewer.label': 'Preview',
    'viewer.close': 'Close the preview',
    'viewer.unviewable': 'This kind of file cannot be shown here.',
    'errors.archive_beyond_capacity':
        'This archive is larger than this server can finish sending. Take fewer files at a time.',
    'errors.archive_too_large': 'This archive is larger than the library allows.',
    'errors.archive_too_many_files': 'This archive holds more files than the library allows.',
    'errors.archive_empty': 'There is nothing to put in an archive.',
    'health.open': 'Health report',
    'health.title': 'Health report',
    'health.close': 'Close the report',
    'health.running': 'Looking at this machine…',
    'health.allWell': 'Everything this package can check is in order.',
    'health.somethingToDo': '{count} thing to look at|{count} things to look at',
    'health.level.error': 'Failing',
    'health.level.warning': 'Worth a look',
    'health.level.ok': 'Fine',
    'actions.purge': 'Delete permanently',
    'actions.purge.confirmTitle': 'Delete permanently?',
    'actions.purge.confirmMessage':
        'This cannot be undone, and the files are removed from the storage.',
    'actions.purge.confirmInside':
        '{count} file inside will be deleted too. This cannot be undone, and the files are removed from the storage.|{count} files inside will be deleted too. This cannot be undone, and the files are removed from the storage.',

    'breadcrumb.label': 'Breadcrumb',
    'breadcrumb.root': 'All files',

    'details.alt': 'Alternative text',
    'details.close': 'Close',
    'details.copied': 'Copied',
    'details.copy': 'Copy',
    'details.created': 'Uploaded at',
    'details.dimensions': 'Dimensions',
    'details.empty': 'No selection',
    'details.emptyHint': 'Choose a file to see and edit its details.',
    'details.landscape': 'Landscape',
    'details.name': 'Name',
    'details.orientation': 'Orientation',
    'details.portrait': 'Portrait',
    'details.save': 'Save',
    'details.size': 'Size',
    'details.square': 'Square',
    'details.type': 'Type',
    'details.updated': 'Modified at',
    'details.url': 'Full url',
    'details.use': 'Use this file',

    'dialog.cancel': 'Cancel',
    'dialog.confirm': 'Confirm',

    /* ⚠️ THE WORDS OF A VEIL, NOT OF A BUTTON. This is what is shown over the listing while a
       file is being held; the control that opens a file picker says `upload.label`. */
    'dropzone.hint': 'They are added to the folder you are looking at.',
    'dropzone.label': 'Drop to upload',

    'folders.create': 'New folder',
    'folders.create.cancel': 'Cancel',
    'folders.create.name': 'Name',
    'folders.create.submit': 'Create',
    'folders.create.title': 'New folder',
    'folders.label': 'Folders',

    'gallery.add': 'Add files',
    'gallery.empty': 'No files chosen',
    'gallery.moveDown': 'Move later',
    'gallery.moveUp': 'Move earlier',
    'gallery.remove': 'Remove',

    'grid.count': '{count} media|{count} media',

    'input.choose': 'Choose a file',
    'input.clear': 'Remove',
    'input.empty': 'No file chosen',
    'input.replace': 'Replace',

    'library.empty.description': 'Drop files, or choose them from your computer.',
    'library.empty.title': 'Nothing here yet',
    'library.trash.description': 'What is thrown away lands here, and can be put back.',
    'library.trash.title': 'The trash is empty',
    'library.noResults.description': 'Nothing matches what you searched for.',
    'library.noResults.title': 'No results',

    'menu.label': 'Actions',

    'pages.label': 'Pages',
    'pages.next': 'Next page',
    'pages.previous': 'Previous page',
    /* ⚠️ THE SIZE OF WHAT IS BEING LOOKED AT, not only the position in it. Knowing there are
       312 items changes what somebody does next; "page 2 of 7" alone does not. */
    'pages.summary': '{count} item|{count} items',
    'pages.where': 'Page {page} of {pages}',

    'picker.cancel': 'Cancel',
    'picker.choose': 'Choose',
    'picker.empty': 'Nothing here',
    'picker.search': 'Search',
    'picker.title': 'Choose a file',

    'queue.abort': 'Stop',
    'queue.clear': 'Clear finished',
    'queue.status.aborted': 'Stopped',
    'queue.status.done': 'Done',
    'queue.status.failed': 'Failed',
    'queue.status.pending': 'Waiting',
    'queue.status.uploading': 'Uploading',
    'queue.retry': 'Try again',
    'queue.title': 'Uploads',

    'quota.label': 'Storage used',
    'quota.unlimited': 'Unlimited',

    'selection.chosen': 'Chosen',
    'selection.clear': 'Clear',
    'selection.count': '{count} selected|{count} selected',

    'skeleton.loading': 'Loading',

    'toolbar.allTypes': 'Everything',
    'toolbar.done': 'Done',
    'toolbar.search': 'Search',
    'toolbar.select': 'Select',
    'toolbar.trash': 'Trash',
    'toolbar.trashLeave': 'Leave the trash',
    'toolbar.sort': 'Sort by',
    'toolbar.sort.created_at': 'Date added',
    'toolbar.sort.name': 'Name',
    'toolbar.sort.size': 'Size',
    'toolbar.sort.updated_at': 'Last changed',
    'toolbar.sortAscending': 'Sort ascending',
    'toolbar.sortDescending': 'Sort descending',
    'toolbar.type': 'Kind',

    'types.audio': 'Audio',
    'types.document': 'Documents',
    'types.external': 'External',
    'types.image': 'Images',
    'types.other': 'Other',
    'types.video': 'Video',

    'upload.label': 'Add files',
}

const fr: MhMessages = {
    'actions.preview': 'Aperçu',
    'actions.link': 'Copier le lien',
    'actions.rename': 'Renommer',
    'actions.duplicate': 'Dupliquer',
    'actions.regenerate': 'Régénérer la miniature',
    'actions.download': 'Télécharger',
    'actions.archive': 'Télécharger en ZIP',
    'actions.trash': 'Déplacer vers la corbeille',
    'actions.trash.confirmTitle': 'Mettre à la corbeille ?',
    'actions.trash.confirmMessage': 'Ces fichiers pourront être restaurés depuis la corbeille.',
    'actions.trash.confirmInside':
        '{count} fichier à l’intérieur partira avec, et pourra être restauré depuis la corbeille.|{count} fichiers à l’intérieur partiront avec, et pourront être restaurés depuis la corbeille.',
    'actions.restore': 'Restaurer',
    'rename.title': 'Renommer',
    'rename.field': 'Nom',
    'rename.save': 'Renommer',
    'rename.cancel': 'Annuler',
    'viewer.label': 'Aperçu',
    'viewer.close': 'Fermer l’aperçu',
    'viewer.unviewable': 'Ce type de fichier ne peut pas être affiché ici.',
    'errors.archive_beyond_capacity':
        'Cette archive dépasse ce que ce serveur peut finir d’envoyer. Prenez moins de fichiers à la fois.',
    'errors.archive_too_large': 'Cette archive dépasse ce que la médiathèque autorise.',
    'errors.archive_too_many_files':
        'Cette archive contient plus de fichiers que la médiathèque n’autorise.',
    'errors.archive_empty': 'Il n’y a rien à mettre dans une archive.',
    'health.open': 'Bilan de santé',
    'health.title': 'Bilan de santé',
    'health.close': 'Fermer le bilan',
    'health.running': 'Examen de la machine…',
    'health.allWell': 'Tout ce que ce paquet sait vérifier est en ordre.',
    'health.somethingToDo': '{count} point à regarder|{count} points à regarder',
    'health.level.error': 'En échec',
    'health.level.warning': 'À regarder',
    'health.level.ok': 'Correct',
    'actions.purge': 'Supprimer définitivement',
    'actions.purge.confirmTitle': 'Supprimer définitivement ?',
    'actions.purge.confirmMessage':
        'Cette action est irréversible, et les fichiers sont retirés du stockage.',
    'actions.purge.confirmInside':
        '{count} fichier à l’intérieur sera supprimé aussi. Cette action est irréversible, et les fichiers sont retirés du stockage.|{count} fichiers à l’intérieur seront supprimés aussi. Cette action est irréversible, et les fichiers sont retirés du stockage.',

    'breadcrumb.label': "Fil d'ariane",
    'breadcrumb.root': 'Tous les fichiers',

    'details.alt': 'Texte alternatif',
    'details.close': 'Fermer',
    'details.copied': 'Copié',
    'details.copy': 'Copier',
    'details.created': 'Ajouté le',
    'details.dimensions': 'Dimensions',
    'details.empty': 'Aucune sélection',
    'details.emptyHint': 'Choisissez un fichier pour voir et modifier ses informations.',
    'details.landscape': 'Paysage',
    'details.name': 'Nom',
    'details.orientation': 'Orientation',
    'details.portrait': 'Portrait',
    'details.save': 'Enregistrer',
    'details.size': 'Taille',
    'details.square': 'Carré',
    'details.type': 'Type',
    'details.updated': 'Modifié le',
    'details.url': 'URL complète',
    'details.use': 'Utiliser ce fichier',

    'dialog.cancel': 'Annuler',
    'dialog.confirm': 'Confirmer',

    'dropzone.hint': 'Ils seront ajoutés au dossier affiché.',
    'dropzone.label': 'Déposez pour envoyer',

    'folders.create': 'Nouveau dossier',
    'folders.create.cancel': 'Annuler',
    'folders.create.name': 'Nom',
    'folders.create.submit': 'Créer',
    'folders.create.title': 'Nouveau dossier',
    'folders.label': 'Dossiers',

    'gallery.add': 'Ajouter des fichiers',
    'gallery.empty': 'Aucun fichier choisi',
    'gallery.moveDown': 'Déplacer après',
    'gallery.moveUp': 'Déplacer avant',
    'gallery.remove': 'Retirer',

    /* ⚠️ ZERO TAKES THE SINGULAR IN FRENCH — « 0 fichier ». */
    'grid.count': '{count} fichier|{count} fichiers',

    'input.choose': 'Choisir un fichier',
    'input.clear': 'Retirer',
    'input.empty': 'Aucun fichier choisi',
    'input.replace': 'Remplacer',

    'library.empty.description': 'Déposez des fichiers, ou choisissez-les sur votre ordinateur.',
    'library.empty.title': 'Rien ici pour le moment',
    'library.trash.description': 'Ce qui est jeté arrive ici, et peut en ressortir.',
    'library.trash.title': 'La corbeille est vide',
    'library.noResults.description': 'Aucun fichier ne correspond à votre recherche.',
    'library.noResults.title': 'Aucun résultat',

    'menu.label': 'Actions',

    'pages.label': 'Pages',
    'pages.next': 'Page suivante',
    'pages.previous': 'Page précédente',
    'pages.summary': '{count} élément|{count} éléments',
    'pages.where': 'Page {page} sur {pages}',

    'picker.cancel': 'Annuler',
    'picker.choose': 'Choisir',
    'picker.empty': 'Rien ici',
    'picker.search': 'Rechercher',
    'picker.title': 'Choisir un fichier',

    'queue.abort': 'Arrêter',
    'queue.clear': 'Effacer les terminés',
    'queue.status.aborted': 'Arrêté',
    'queue.status.done': 'Terminé',
    'queue.status.failed': 'Échec',
    'queue.status.pending': 'En attente',
    'queue.status.uploading': 'Envoi en cours',
    'queue.retry': 'Réessayer',
    'queue.title': 'Envois',

    'quota.label': 'Espace utilisé',
    'quota.unlimited': 'Illimité',

    'selection.chosen': 'Choisi',
    'selection.clear': 'Tout désélectionner',
    'selection.count': '{count} sélectionné|{count} sélectionnés',

    'skeleton.loading': 'Chargement',

    'toolbar.allTypes': 'Tout',
    'toolbar.done': 'Terminer',
    'toolbar.search': 'Rechercher',
    'toolbar.select': 'Sélectionner',
    'toolbar.trash': 'Corbeille',
    'toolbar.trashLeave': 'Quitter la corbeille',
    'toolbar.sort': 'Trier par',
    'toolbar.sort.created_at': "Date d'ajout",
    'toolbar.sort.name': 'Nom',
    'toolbar.sort.size': 'Taille',
    'toolbar.sort.updated_at': 'Dernière modification',
    'toolbar.sortAscending': 'Trier par ordre croissant',
    'toolbar.sortDescending': 'Trier par ordre décroissant',
    'toolbar.type': 'Type',

    'types.audio': 'Audio',
    'types.document': 'Documents',
    'types.external': 'Externe',
    'types.image': 'Images',
    'types.other': 'Autres',
    'types.video': 'Vidéos',

    'upload.label': 'Ajouter des fichiers',
}

export const MH_LOCALES: Record<string, MhLocale> = {
    en: { messages: en, plural: (count) => (count === 1 ? 0 : 1) },

    /* ⚠️ `count <= 1`, NOT `count === 1`: French says « 0 fichier », not « 0 fichiers ». */
    fr: { messages: fr, plural: (count) => (count <= 1 ? 0 : 1) },
}

export const MH_DEFAULT_LOCALE = 'en'
