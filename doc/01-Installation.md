<a id="Installation"></a>Installation
=====================================

Requirements
------------

* Icinga Web 2 (&gt;= 2.4.1)
* Icinga Director (&gt;= 1.30)
* PHP (&gt;= 5.4 or 7.x)

The Icinga Web 2 `monitoring` module needs to be configured and enabled.

Installation from .tar.gz
-------------------------

Download the latest version and extract it to a folder named `pve`
in one of your Icinga Web 2 module path directories.

You might want to use a script as follows for this task:
```sh
ICINGAWEB_MODULEPATH="/usr/share/icingaweb2/modules"
REPO_URL="https://github.com/nbuchwitz/icingaweb2-module-pve"
TARGET_DIR="${ICINGAWEB_MODULEPATH}/pve"
MODULE_VERSION="0.2.0"
URL="${REPO_URL}/archive/v${MODULE_VERSION}.tar.gz"
install -d -m 0755 "${TARGET_DIR}"
wget -q -O - "$URL" | tar xfz - -C "${TARGET_DIR}" --strip-components 1
```

Installation from GIT repository
--------------------------------

Another convenient method is the installation directly from our GIT repository.
Just clone the repository to one of your Icinga Web 2 module path directories.
It will be immediately ready for use:

```sh
ICINGAWEB_MODULEPATH="/usr/share/icingaweb2/modules"
REPO_URL="https://github.com/nbuchwitz/icingaweb2-module-pve"
TARGET_DIR="${ICINGAWEB_MODULEPATH}/pve"
git clone "${REPO_URL}" "${TARGET_DIR}"
```

You can now directly use our current GIT master or check out a specific version.

Enable the newly installed module
---------------------------------

Enable the `pve` module either on the CLI by running

```sh
icingacli module enable pve
```

Or go to your Icinga Web 2 frontend, choose `Configuration` -&gt; `Modules`, chose the `pve` module and `enable` it.

It might afterwards be necessary to refresh your web browser to be sure that
newly provided styling is loaded.