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

            'declare' => 'Réglez mediahub.archives.time_budget sur le nombre de secondes qu\'un téléchargement peut réellement durer ici — votre request_terminate_timeout PHP-FPM et le délai de votre proxy, le plus petit des deux. Ni l\'un ni l\'autre ne se lisent depuis PHP : tant que rien n\'est déclaré, le paquet suppose soixante secondes.',
            'lower' => 'Abaissez mediahub.archives.max_bytes à :deliverable, ou relevez mediahub.archives.time_budget et les délais qui sont derrière.',
        ],

        'buffering' => [
            'title' => 'Mise en tampon de la sortie (zlib.output_compression)',
            'ok' => 'Désactivée : les archives partent directement dans la connexion.',
            'warning' => 'Activée. Le paquet la coupe pour ses propres réponses, donc les archives sont bien diffusées — mais tout ce qui diffuse par ailleurs dans cette application est d\'abord retenu en mémoire.',
            'error' => 'Activée, et impossible à changer depuis PHP sur cette machine. Chaque octet d\'une archive est retenu en mémoire avant que le moindre ne parte : une archive volumineuse épuise la limite mémoire au lieu de se télécharger.',

            'fix' => 'Mettez zlib.output_compression à Off dans php.ini. Recompresser un ZIP coûte du temps processeur pour rien, de toute façon.',
        ],
    ],

    'images' => [

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
