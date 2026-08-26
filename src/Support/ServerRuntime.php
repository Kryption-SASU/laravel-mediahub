<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

/**
 * WHICH PHP IS ANSWERING, AND THEREFORE WHERE THIS MACHINE'S REAL CEILING IS WRITTEN.
 *
 * ⚠️ ADVICE THAT NAMES THE WRONG FILE IS WORSE THAN NO ADVICE. "Raise
 * `request_terminate_timeout` in `php-fpm.conf`" is exact on a machine running PHP-FPM and
 * nonsense on one running mod_php, where neither the setting nor the file exists. Somebody
 * reads it, searches for a file they do not have, and concludes the report is describing a
 * different product. The health check has to know which runtime it is standing in before it
 * tells anybody what to change.
 *
 * ⚠️ AND THE RUNTIMES DIFFER IN KIND, NOT ONLY IN NAMES. Under PHP-FPM a request has a hard
 * wall-clock ceiling that kills it outright. Under mod_php there is no such thing: nothing in
 * Apache bounds how long a request may run, only how long the connection may sit idle. Those
 * two machines need opposite sentences, and a report that has one of them memorised is wrong on
 * the other half of the world's servers.
 *
 * ⚠️ WHAT IS NOT RECOGNISED IS SAID TO BE UNRECOGNISED. LiteSpeed, FrankenPHP, RoadRunner and
 * whatever comes next each bound a request their own way, and guessing at one of them would
 * produce exactly the confident-but-wrong sentence this class exists to prevent. An honest
 * "the package cannot name yours" leaves somebody able to go and look; a wrong file name does
 * not.
 */
final class ServerRuntime
{
    /** A request has a hard ceiling set by the process manager, invisible from in here. */
    public const POOLED = 'fpm';

    /** PHP lives inside the web server. Nothing bounds a request's duration. */
    public const EMBEDDED = 'module';

    /** PHP is talked to over CGI, and the thing doing the talking gives up first. */
    public const GATEWAY = 'cgi';

    /** No web server at all — and therefore not the runtime that serves the site. */
    public const CONSOLE = 'cli';

    /** Something real, but not something this package can speak about. */
    public const UNRECOGNISED = 'unknown';

    /**
     * ⚠️ THE INTERFACE NAMES ARE PHP'S, NOT OURS, and they are not guessable: the Apache module
     * calls itself `apache2handler`, and PHP-FPM calls itself `fpm-fcgi` rather than anything
     * containing "fpm" alone. Matching on substrings would put `cgi-fcgi` — plain FastCGI,
     * which has no pool manager — into the pooled family and hand it a `php-fpm.conf` that
     * is not there.
     */
    private const FAMILIES = [
        'fpm-fcgi' => self::POOLED,
        'apache2handler' => self::EMBEDDED,
        'apache' => self::EMBEDDED,
        'cgi-fcgi' => self::GATEWAY,
        'cgi' => self::GATEWAY,
        'cli' => self::CONSOLE,
        'cli-server' => self::CONSOLE,
        'phpdbg' => self::CONSOLE,
        'embed' => self::CONSOLE,
    ];

    /**
     * ⚠️ INJECTED RATHER THAN READ, so a bench can stand in a runtime it is not running in.
     * Neither of these can be produced at runtime: `PHP_SAPI` is a compile-time constant of the
     * interpreter, and `disable_functions` is `PHP_INI_SYSTEM`. A class that read them directly
     * could only ever be tested against whichever machine the suite happens to run on — leaving
     * the branches that matter to shared hosting as the branches no test ever enters.
     *
     * @param  bool|null  $timeLimitIsLiftable  null asks the running PHP
     */
    public function __construct(
        private readonly string $sapi = PHP_SAPI,
        private readonly ?bool $timeLimitIsLiftable = null,
    ) {
    }

    /** What PHP calls the interface it is answering through. */
    public function sapi(): string
    {
        return $this->sapi;
    }

    public function family(): string
    {
        return self::FAMILIES[strtolower($this->sapi)] ?? self::UNRECOGNISED;
    }

    /**
     * ⚠️ A REPORT PRODUCED FROM THE CONSOLE DESCRIBES THE CONSOLE. Its memory limit, its
     * execution time and its extensions are those of the command line, which routinely differ
     * from the ones the site runs under — a separate `php.ini` is the normal arrangement, not an
     * exotic one. Read as a verdict on the server, such a report is confidently wrong about
     * every number in it.
     */
    public function servesTheWeb(): bool
    {
        return $this->family() !== self::CONSOLE;
    }

    /**
     * WHETHER THIS PACKAGE CAN GIVE ITSELF THE TIME TO FINISH AN ARCHIVE.
     *
     * ⚠️ `function_exists` IS THE WHOLE ANSWER, MEASURED RATHER THAN ASSUMED. PHP removes a
     * function named in `disable_functions` from its table outright — `php -d
     * disable_functions=set_time_limit -r 'var_dump(function_exists("set_time_limit"));'` prints
     * `false` — so reading the directive as well would be a second look at the same fact.
     *
     * ⚠️ AND IT IS ASKED HERE RATHER THAN AT THE POINT OF USE, so that the health report and the
     * streamer cannot disagree. A report saying the limit will be lifted, beside a stream that
     * silently could not, is worse than no report: it is the sentence somebody trusts while
     * looking for the fault somewhere else.
     */
    public function canLiftTheTimeLimit(): bool
    {
        return $this->timeLimitIsLiftable ?? function_exists('set_time_limit');
    }
}
