Friendica translations
======================

Translation Process
-------------------

The strings used in the UI of Friendica is translated at [Transifex] [1] and then included in the git repository at github.
If you want to help with translation for any language, be it correcting terms or translating friendica to a currently not supported language, please register an account at transifex.com and contact the friendica translation team there.

Translating friendica is simple.
Just use the online tool at transifex.
If you don't want to deal with git & co. that is fine, we check the status of the translations regularly and import them into the source tree at github so that others can use them.

We do not include every translation from transifex in the source tree to avoid a scattered and disturbed overall experience.
As an uneducated guess we have a lower limit of 50% translated strings before we include the language (for the core messages.po file, addont translation will be included once all strings of an addon are translated.
This limit is judging only by the amount of translated strings under the assumption that the most prominent strings for the UI will be translated first by a translation team.
If you feel your translation useable before this limit, please contact us and we will probably include your teams work in the source tree.

If you want to help translating, please concentrate on the core messages.po file first.
We will only include translations with a sufficient translated messages.po file.
Translations of addons will only be included, when the core file is included as well.

If you want to get your work into the source tree yourself, feel free to do so and contact us with and question that arises.
The process is simple and friendica ships with all the tools necessary.

The location of the translated files in the source tree is
    /view/lang/LNG-CODE/
where LNG-CODE is the language code used, e.g. de for German or fr for French.
The translated strings come as a "message.po" file from transifex which needs to be translated into the PHP file friendica uses.
To do so, place the file in the directory mentioned above and use the "po2php" command from the console.

Assuming you want to convert the German localization which is placed in view/lang/de/message.po you would do the following.

    1. Navigate at the command prompt to the base directory of your
       friendica installation

    2. Execute the po2php command, which will place the translation
       in the strings.php file that is used by friendica.

       $> php bin/console.php po2php view/lang/de/messages.po

       The output of the script will be placed at view/lang/de/strings.php where
       friendica is expecting it, so you can test your translation immediately.

    3. Visit your friendica page to check if it still works in the language you
       just translated. If not try to find the error, most likely PHP will give
       you a hint in the log/warnings.about the error.

       For debugging you can also try to "run" the file with PHP. This should
       not give any output if the file is ok but might give a hint for
       searching the bug in the file.

       $> php view/lang/de/strings.php

    4. commit the two files with a meaningful commit message to your git
       repository, push it to your fork of the friendica repository at github and
       issue a pull request for that commit.

You should translate the PO files at Transifex.
Otherwise your work might get lost, when the translation from Transifex is included to the Friendica repository after it was updated there.

Utilities
---------

Additional to the po2php command there are some more utilities for translation in the console.
If you only want to translate friendica into another language you wont need any of these tools most likely but it gives you an idea how the translation process of friendica works.

For further information see the utils/README file.

Transifex-Client
----------------

Transifex has a client program which let you interact with the translation files in a similar way to git.
Help for the client can be found at the [Transifex Help Center] [2].
Here we will only cover basic usage.

After installation of the client, you should have a `tx` command available on your system.
To use it, first create a configuration file with your credentials.
On Linux this file should be placed into your home directory `~/.transifexrc`.
The content of the file should be something like the following:

    [https://www.transifex.com]
    username = user
    token =
    password = p@ssw0rd
    hostname = https://www.transifex.com

Since Friendica version 3.5.1 we ship configuration files for the Transifex client in the core repository and the addon repository.
To update the translation files after you have translated strings of e.g. Esperanto in the web-UI of transifex you can use `tx` to download the file.

    $> tx pull -l eo

And then use the `po2php` command described above to convert the `messages.po` file to the `strings.php` file Friendica is loading.

    $> php bin/console.php po2php view/lang/eo/messages.po

Afterwards, just commit the two changed files to a feature branch of your Friendica repository, push the changes to github and open a pull request for your changes.

[1]:   https://www.transifex.com/projects/p/friendica/
[2]:   https://docs.transifex.com/client/introduction
