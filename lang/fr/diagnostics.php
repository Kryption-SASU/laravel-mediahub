<?php

declare(strict_types=1);

/*
 * Le bilan de santé, en toutes lettres.
 *
 * ⚠️ CHAQUE RECOMMANDATION NOMME UNE DIRECTIVE ET UNE VALEUR. « post_max_size est trop petit »
 * envoie quelqu'un vers un moteur de recherche ; « mettez-le à 200M au moins, ou abaissez
 * mediahub.uploads.max_size à 8000 » est une décision qui se prend en une minute.
 *
 * ⚠️ LA STRUCTURE EST IMBRIQUÉE, ET CE N'EST PAS UNE QUESTION DE GOÛT. Une clé de la forme
 * `'uploads.post_max_size' => ['title' => …]` est inatteignable : à qui demande
 * `uploads.post_max_size.title`, Laravel essaie la clé littérale entière, échoue, puis descend
 * segment par segment en cherchant `['uploads']['post_max_size']['title']` — et ne trouve rien.
 * Il rend alors la clé elle-même, et c'est `mediahub::diagnostics.uploads.post_max_size.title`
 * qui s'affiche à qui essaie de régler un serveur. Livré ainsi une fois, vu sur un vrai écran.
 */

return [

    'runtime' => [

        'sapi' => [
            'title' => 'Comment PHP tourne ici (:sapi)',
            'ok' => 'Les requêtes passent par l\'interface :sapi. Ce qui borne un téléchargement long ici, c\'est :timeouts — rien de tout cela ne se lit depuis PHP, et c\'est la raison pour laquelle le budget d\'archive plus bas se déclare au lieu de se détecter.',
            'warning' => 'Ce bilan a été produit depuis la ligne de commande, qui n\'est pas l\'interface servant votre site. Toutes les limites ci-dessous sont celles de la console : un php.ini distinct pour elle est l\'usage courant, donc ces chiffres peuvent n\'avoir aucun rapport avec ceux que rencontrent vos visiteurs.',

            'fix' => 'Ouvrez le bilan depuis un navigateur, pour qu\'il lise l\'environnement qui répond réellement aux requêtes.',
        ],

        /*
         * ⚠️ UNE PHRASE PAR FAMILLE, ET TOUT L'INTÉRÊT EST LE FICHIER VERS LEQUEL ELLE ENVOIE.
         * `request_terminate_timeout` est exact sous PHP-FPM et n'existe pas sous mod_php : un
         * hébergement Apache à qui l'on dit d'éditer `php-fpm.conf` cherche un fichier qu'il n'a
         * pas, et en conclut que le bilan parle d'autre chose.
         */
        'timeouts' => [
            'fpm' => 'le request_terminate_timeout de votre pool PHP-FPM et le délai de proxy de votre serveur frontal, le plus petit des deux',
            'module' => 'votre serveur frontal ou votre CDN, s\'il y en a un — mod_php ne borne pas la durée d\'une requête, et le Timeout d\'Apache ne se déclenche que si la connexion se bloque, pas si elle est simplement lente',
            'cgi' => 'le délai de ce qui parle FastCGI à PHP — fastcgi_read_timeout sous nginx, FcgidIOTimeout sous mod_fcgid, soixante secondes par défaut dans les deux cas',
            'cli' => 'rien du tout en ligne de commande, et c\'est précisément l\'environnement dont les chiffres en disent le moins sur votre site',
            'unknown' => 'ce qui supervise cette interface — ce paquet ne la reconnaît pas, et préfère le dire plutôt que vous envoyer vers un fichier de configuration que vous n\'avez pas',
        ],
    ],

    'uploads' => [

        'upload_max_filesize' => [
            'title' => 'Taille de fichier acceptée par PHP (upload_max_filesize)',
            'ok' => 'PHP accepte :allowed par fichier ; la médiathèque en demande :wanted.',
            'error' => 'PHP refuse tout ce qui dépasse :allowed, alors que la médiathèque est réglée pour accepter :wanted. Les fichiers entre les deux sont rejetés avant que ce paquet ne s\'exécute, avec une réponse vide et aucune explication.',
        ],

        'post_max_size' => [
            'title' => 'Taille de requête acceptée par PHP (post_max_size)',
            'ok' => 'PHP accepte des requêtes de :allowed ; la médiathèque en demande :wanted.',
            'error' => 'PHP refuse les requêtes au-delà de :allowed, alors que la médiathèque est réglée pour accepter :wanted. Cette limite borne la requête entière — le fichier et ses champs — elle doit donc être plus grande que la limite du fichier, pas égale.',
        ],

        'fix' => 'Portez :directive à :wanted au moins dans php.ini, ou abaissez mediahub.uploads.max_size à :kilobytes (en kilo-octets).',
    ],

    'archives' => [

        'capacity' => [
            'title' => 'Taille d\'archive que cette machine peut finir d\'envoyer',
            'ok' => 'Les archives sont plafonnées à :configured, ce que cette machine devrait livrer.',
            'warning' => 'La configuration autorise :configured, alors que cette machine ne devrait finir que :deliverable. Au-delà, c\'est refusé avant de commencer — et c\'est voulu : une archive coupée en cours de route a déjà envoyé son 200, donc elle se télécharge et s\'ouvre avec des fichiers manquants.',

            /*
             * ⚠️ DEUX-POINTS PLUTÔT QUE « C'EST », ET CE N'EST PAS UN DÉTAIL DE STYLE. La phrase
             * accueille cinq compléments différents selon l'environnement, dont « rien du tout
             * en ligne de commande » : « ce qui le borne, c'est rien du tout » est fautif, et vu
             * sur le vrai écran. Les deux-points acceptent les cinq sans accord à faire.
             */
            'declare' => 'Réglez mediahub.archives.time_budget sur le nombre de secondes qu\'un téléchargement peut réellement durer ici. Ce qui le borne sur cette machine : :timeouts. Rien de tout cela ne se lit depuis PHP : tant que rien n\'est déclaré, le paquet suppose soixante secondes.',
            'lower' => 'Abaissez mediahub.archives.max_bytes à :deliverable, ou relevez mediahub.archives.time_budget et les délais qui sont derrière — ici, :timeouts.',
        ],

        'execution_time' => [
            'title' => 'Temps qu\'une archive peut passer à compresser (max_execution_time)',
            'ok' => 'PHP lui-même ne coupe pas une archive ici : :because.',
            'warning' => 'PHP arrête un script au bout de :limit secondes, et set_time_limit est désactivée sur cette machine : le paquet ne peut donc pas relever la limite pour la réponse qu\'il diffuse. L\'attente du stockage ne compte pas dans cette limite, mais la compression si — une grosse archive de fichiers pas déjà compressés peut l\'atteindre, et elle est alors tuée après le début du téléchargement, laissant un ZIP qui s\'ouvre avec des fichiers manquants.',

            'because' => [
                'absent' => 'PHP n\'impose aucune limite de temps d\'exécution',
                'lifted' => 'le paquet lève la limite de :limit secondes pour la réponse qu\'il diffuse',
            ],

            'fix' => 'Relevez max_execution_time dans php.ini, ou retirez set_time_limit de disable_functions pour que le paquet puisse la lever là où il en a besoin.',
        ],

        'progress' => [
            'title' => 'Suivi d\'un téléchargement en cours (magasin de cache : :store)',
            'ok' => 'Le magasin :store se relit depuis une seconde requête : la médiathèque peut donc afficher l\'avancement d\'une archive. ⚠️ Derrière un répartiteur de charge, vérifiez qu\'il est aussi partagé entre vos serveurs — apc et octane sont propres à une machine, et file l\'est aussi tant que le répertoire ne l\'est pas.',
            'warning' => 'Le magasin :store naît et meurt dans une seule requête : à la question « où en est cette archive ? », une seconde requête s\'entend toujours répondre « jamais entendu parler ». Les archives se téléchargent quand même ; aucun avancement n\'est affiché, et rien à l\'écran n\'en donne la raison.',

            'fix' => 'Pointez mediahub.archives.progress_store sur un magasin partageable entre deux requêtes — redis, memcached, database ou file — ou changez le magasin par défaut de l\'application. Rien d\'autre n\'en dépend : ne rien faire coûte le pourcentage, et rien de plus.',
        ],

        'buffering' => [
            'title' => 'Mise en tampon de la sortie (zlib.output_compression)',
            'ok' => 'Désactivée : les archives partent directement dans la connexion.',
            'warning' => 'Activée. Le paquet la coupe pour ses propres réponses, donc les archives sont bien diffusées — mais tout ce qui diffuse par ailleurs dans cette application est d\'abord retenu en mémoire.',
            'error' => 'Activée, et impossible à changer depuis PHP sur cette machine. Chaque octet d\'une archive est retenu en mémoire avant que le moindre ne parte : une archive volumineuse épuise la limite mémoire au lieu de se télécharger.',

            'fix' => 'Mettez zlib.output_compression à Off dans php.ini. Recompresser un ZIP coûte du temps processeur pour rien, de toute façon.',
        ],
    ],

    /*
     * ⚠️ LE CHEMIN RÉSOLU EST À L'ÉCRAN, pas seulement « trouvé ». Un hôte qui a trois ffmpeg et
     * un chemin configuré n'a qu'une question — lequel tourne réellement — et c'est celle à
     * laquelle un oui/non ne répond pas.
     */
    'tools' => [

        'programs' => [
            'title' => 'Lancer un programme depuis une requête',
            'ok' => 'Autorisé.',
            'warning' => "Interdit sur cette installation : proc_open n'est pas disponible, donc aucun outil ci-dessus n'a pu être interrogé et aucun ne peut être lancé pendant une requête. Les miniatures fabriquées par un worker de file ne sont pas concernées — la ligne de commande tourne le plus souvent sous une autre configuration.",
            'fix' => "Rien à changer si un worker de file fabrique les dérivés ; vérifiez qu'il tourne. Autoriser proc_open côté serveur web marcherait aussi, et donnerait à toute requête le droit de lancer des programmes — une permission bien plus large que ce qu'une miniature demande.",
        ],

        'ffmpeg' => [
            'title' => 'ffmpeg — miniatures des vidéos',
            'ok' => 'Trouvé dans :path (:version).',
            'warning' => 'Absent : les vidéos gardent leur icône de type au lieu d\'une image. Rien d\'autre n\'est touché — les fichiers s\'envoient, se téléchargent et se lisent comme avant.',
        ],

        'ffprobe' => [
            'title' => 'ffprobe — la durée d\'une vidéo',
            'ok' => 'Trouvé dans :path (:version).',
            'warning' => 'Absent. La capture reste possible, mais la seconde demandée ne peut plus être confrontée à la durée du fichier — et une capture au-delà de la fin d\'une vidéo ne produit rien du tout, en silence.',
        ],

        'pdf' => [
            'title' => 'La première page d\'un PDF (:tool)',
            'ok' => 'Rendue par :tool, trouvé dans :path (:version).',
            'warning' => 'Ni pdftoppm ni gs n\'est disponible : les documents gardent leur icône de type au lieu d\'une image de leur première page.',

            'fix' => 'Installez poppler-utils, qui fournit pdftoppm — ou pointez mediahub.tools.pdf sur un moteur que vous avez déjà. ⚠️ Ghostscript fonctionne et est accepté, mais poppler est préféré quand les deux sont là : gs est un interpréteur PostScript complet, ce qui a valu à ImageMagick ses failles les plus graves, là où pdftoppm ne fait que dessiner des pages.',
        ],

        'fix_missing' => 'Installez :tool, ou pointez mediahub.tools.:tool dessus s\'il vit là où le PATH ne va pas — un worker de file d\'attente a rarement le même PATH qu\'un shell.',
        'fix_configured' => 'mediahub.tools.:tool nomme un chemin qui n\'est pas un fichier exécutable ici. ⚠️ Rien n\'a été utilisé à la place : retomber sur ce que trouve le PATH ferait tourner un autre programme que celui que vous avez nommé, sans rien en dire.',
    ],

    'images' => [

        /*
         * ⚠️ CE QU'IL SAIT FAIRE, JAMAIS CE QU'IL ANNONCE. `queryFormats()` est une réclame :
         * il répond « oui » pour MP4, MOV et PDF sur des machines où les trois échouent. Ce
         * paquet s'y est fait prendre deux fois.
         */
        'imagick' => [
            'title' => 'Ce qu\'ImageMagick sait réellement lire ici',
            'ok' => 'Éprouvé format par format : :proven. ⚠️ C\'est ce qu\'il sait faire, pas ce qu\'il annonce — queryFormats() répond « oui » pour MP4, MOV et PDF sur des machines où chacun échoue, parce que les formats vidéo passent par un délégué et que les distributions les coupent tous dans policy.xml.',
        ],


        'memory' => [
            'title' => 'Mémoire face à la plus grande image acceptée',
            'ok' => 'Décoder la plus grande image acceptée demande environ :needed, dans les :limit disponibles.',
            'warning' => 'La plus grande image acceptée fait :megapixels mégapixels, soit environ :needed à décoder — plus que les :limit autorisés par PHP. Ce sont les pixels qui épuisent la mémoire, pas le poids du fichier : une photo de quelques méga-octets en pèse des centaines une fois décodée.',

            'fix' => 'Relevez memory_limit au-dessus de :needed, ou abaissez mediahub.uploads.max_image_pixels.',
        ],
    ],

    'extensions' => [

        'zip' => [
            'title' => 'L\'extension zip',
            'ok' => 'Chargée.',
            'error' => 'Absente. Télécharger un dossier ou un lot en archive ne peut pas fonctionner sans elle.',
        ],

        'fileinfo' => [
            'title' => 'L\'extension fileinfo',
            'ok' => 'Chargée.',
            'error' => 'Absente. Le vrai type d\'un fichier envoyé se lit dans son contenu ; sans elle il ne reste que l\'extension fournie par le client, et un exécutable renommé en .jpg passe.',
        ],

        'gd' => [
            'title' => 'L\'extension gd',
            'ok' => 'Chargée.',
            'warning' => 'Absente, alors que c\'est le moteur d\'images choisi. Les fichiers sont toujours stockés et servis ; aucune vignette n\'est produite.',
        ],

        'imagick' => [
            'title' => 'L\'extension imagick',
            'ok' => 'Chargée.',
            'warning' => 'Absente, alors que c\'est le moteur d\'images choisi. Les fichiers sont toujours stockés et servis ; aucune vignette n\'est produite.',
        ],

        'fix' => 'Installez l\'extension :extension, ou choisissez une configuration qui s\'en passe.',
    ],

];
