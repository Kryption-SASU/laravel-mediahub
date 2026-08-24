The package's events.

⚠️ **THIS IS THE EXTENSION POINT THAT AVOIDS A FORK.** A host that wants to log, index, notify
or invalidate a cache attaches a listener. The module this package replaces emitted none: every
need of that kind was met by modifying its code, and it is the sum of those modifications that
made upgrading it impossible.

⚠️ **THEY CARRY THE MODEL, NOT AN IDENTIFIER.** After a permanent deletion the row no longer
exists: a listener receiving only a key would have nothing left to query.
