# Chapter 2: Discovering the Standard Library

PHP has a rich and colorful standard library. Every function, from `array_push` to `zip_open`, can be found in modern versions of the language. There are functions and classes that serve many and varied purposes. They’re mainly divided into the following categories:

1. [Core Behaviour](http://php.net/manual/en/refs.basic.php.php)
2. [Audio Manipulation](http://php.net/manual/en/refs.utilspec.audio.php)
3. [Authentication](http://php.net/manual/en/refs.remote.auth.php)
4. [Command Line](http://php.net/manual/en/refs.utilspec.cmdline.php)
5. [Compression and Archival](http://php.net/manual/en/refs.compression.php)
6. [Credit Card Processing](http://php.net/manual/en/refs.creditcard.php)
7. [Cryptography](http://php.net/manual/en/refs.crypto.php)
8. [Databases](http://php.net/manual/en/refs.database.php)
9. [Date and Time](http://php.net/manual/en/refs.calendar.php)
10. [File System](http://php.net/manual/en/refs.fileprocess.file.php)
11. [Human Language and Character Encoding](http://php.net/manual/en/refs.international.php)
12. [Image Processing and Generation](http://php.net/manual/en/refs.utilspec.image.php)
13. [Email](http://php.net/manual/en/refs.remote.mail.php)
14. [Mathematics](http://php.net/manual/en/refs.math.php)
15. [Process Control](http://php.net/manual/en/refs.fileprocess.process.php)
16. [Search Engines](http://php.net/manual/en/refs.search.php)
17. [Servers](http://php.net/manual/en/refs.utilspec.server.php)
18. [Sessions](http://php.net/manual/en/refs.basic.session.php)
19. [Text processing](http://php.net/manual/en/refs.basic.text.php)
20. [Variables](http://php.net/manual/en/refs.basic.vartype.php)
21. [Web Services](http://php.net/manual/en/refs.webservice.php)
22. [XML Manipulation](http://php.net/manual/en/refs.xml.php)

Most of these topics are common in PHP applications. Many of them interact with processes and servers external to the PHP script they’re a part of.

I can’t remember the last time I built an application that didn’t need a database, access to the file system, or some kind of image processing. They involve traditionally blocking network and file system operations, and increase the time a script spends waiting for *other things* to happen.

## Spawning Processes

PHP scripts are typically single-process, single-threaded. What happens when we need an infinite loop or a blocking operation? Everything else waits for those to finish. This problem isn’t unique to the PHP language—other single-process, single-threaded things are also affected in this way:

![](/img/bash-infinite-loop.gif)
> Bash infinite loop

When I use [Hyper](https://hyper.is/) on [macOS Sierra](http://www.apple.com/macos/sierra), each tab is a single process. If I run a blocking script, or make an infinite loop, I have to wait for it to finish before I can do anything else. I could make a new tab, and work in that. In fact, that’s the first trick I’m going to show you.

### Shell Functions

There are quite a few standard library functions which will create a new process. They all resemble:

```php
exec("while true \n do \n echo '.' \n sleep 1 \n done");
```

These functions are:

1. [exec](http://php.net/manual/en/function.exec.php)
2. [shell_exec](http://php.net/manual/en/function.shell-exec.php)
3. [system](http://php.net/manual/en/function.system.php)
4. [passthru](http://php.net/manual/en/function.passthru.php)

> I tend to use `exec` and `passthru` most of the time. `exec` is great for executing something in a new process, but don’t want to print the results. `passthru` is ideal for executing something in a new process, and seeing the unbuffered, unaltered results.  

These functions block the PHP process in the same way our Bash example did:

![](/img/passthru-blocking.gif)
> `passthru` blocking process

Fortunately, Bash already provides a way for us to spawn processes in the background. By appending a single `&` to a command, we can make that command run in the background. It will be handled in a new process:

![](/img/passthru-background.gif)
> `passthru` background process

This trick works for two reasons. First, PHP waits around for output from the shell process. When we redirect output, using `>/dev/null 2>/dev/null`, PHP stops waiting for output.

Secondly, Bash waits for a new shell process to finish. That is unless we append `&` to the end of the blocking process, which Bash sees as an instruction to execute the process in the background.

### Identifying Processes

Being able to run slow scripts in the background is great, but it creates a new problem. If we're running things in the background, how do we know when they're done?

> Later on, we'll see extensions which give us greater control over how the processes are started and identified, but it's useful to know how to identify processes when `exec` is the tool we're using.  

The easiest way is to pass additional identifiers to the command that starts the background process:

```php
exec("php slow.php id=something > /dev/null 2> /dev/null &");
```

This command will only take a moment to execute, but it will start `slow.php` in the background. If `slow.php` takes a long time to execute, and we want to know when it's complete, we can query the system processes:

![](/img/identify-processes.gif)
> Tagging processes with a unique identifier

Given a unique identifier, we can query the operating system for processes matching its description:

```php
exec("ps -ax | grep id=something", $output);

var_dump($output);
```

`$output` now contains every running process matching the `id=something` string used when the process began. We can run `ps` periodically to see more or less when the background process has stopped, how long it has been running for, and (with some additional switches) the processing and memory load it has on the system.

We can also run `exec("kill -9 {$pid}")` when we want the background process to stop. This all assumes there is some main process, perhaps running in a loop, to monitor the background processes:

```php
exec("php slow.php id=something > /dev/null 2> /dev/null &");

while (true) {
    // manage background processes:
    // exec("ps -ax | grep id=something", $output);
    // exec("kill -9 {$pid}");
    sleep(1);
}
```

> This approach only works on [POSIX](https://en.wikipedia.org/wiki/POSIX) operating systems, where the `ps` command is available. You can try it on Windows, but I can't vouch for its efficacy there.  

### Bundling Code

Sometimes setting up a separate worker script can be more effort than it's worth. What if we just want to send two or three particularly slow lines of code out of the main process? In cases like that, we can bundle the code and execute it directly from the command line.

First, we'll need to install a dependency called [SuperClosure](https://github.com/jeremeamia/super_closure):

```bash
composer require jeremeamia/superclosure
```

Then, we can use this to convert closures to strings:

```php
require __DIR__ . "/vendor/autoload.php";

use SuperClosure\Serializer;

function defer(Closure $closure) {
    $serializer = new Serializer();

    $serialized = base64_encode(
        $serializer->serialize($closure)
    );

    $autoload = __DIR__ . "/vendor/autoload.php";

    $raw = "
        require_once '{$autoload}';

        \$serializer = new SuperClosure\Serializer();
        \$serialized = base64_decode('{$serialized}');

        call_user_func(
            \$serializer->unserialize(\$serialized)
        );
    ";

    $encoded = base64_encode($raw);

    exec(
        "php -r 'eval(base64_decode(\"{$encoded}\"));'",
        $output
    );

    return $output;
}

$output = defer(function() {
    print "hi";
});
```
> This is from `bundle.php`  

With this new `defer` function, we can convert a `Closure` to a string, using SuperClosure. Then, we encode it and build a source code string which will decode it.

Next, we pass that string to `eval`, using the PHP command line `-r` switch. This switch executes a provided source code string directly, without the need to create a PHP file beforehand.

We can even combine this with what we previously learned about running processes in the background and identifying processes:

```php
function deferWithIdentity(Closure $closure) {
    // ...snip

    $identity = spl_object_hash($serializer);

    exec(
        "php -r '/* id={$identity} */ eval(base64_decode(\"{$encoded}\"));' > /dev/null 2> /dev/null &"
    );

    exec("ps -ax | grep id={$identity}", $output);

    if (count($output) < 1) {
        throw new Exception("process not started");
    }

    $line = $output[0];
    $values = explode(" ", $line);

    return (int) $values[0];
}

$output = deferWithIdentity(function() {
    while (true) {
        sleep(1);
    }
});
```
> This is from `bundle.php`  

If we generate an identifier for the process (even if it's not guaranteed to be unique) and provide it as a comment in the code that is run directly. We can get its process identity in a subsequent `exec` call to the `ps` command.

Instead of returning background process output (which is okay since we're not blocking until it is complete), we return an identifier which we can use to poll. We could also use the identifier to kill the process if we feel it's taking too long to complete.

### Security Concerns

It would be remiss of me to talk about these functions without also talking about the security implications of using them. These functions execute system commands, as the same user for which the PHP interpreter is running.

You should never use the functions to execute commands composed of user provided data. You should only use them to execute commands you are certain cannot compromise the system.

If you are going to make dynamic commands (which you should definitely try to avoid), then you should at least run all dynamic parts of those commands through a function like [`escapeshellarg`](http://php.net/manual/en/function.escapeshellarg.php).

## Using Symfony Process

If you’d prefer not to use `exec`, `passthru`, or any of the other shell functions directly, you can use the [Symfony Process](https://github.com/symfony/process) library. It’s an abstraction around these functions:

```php
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

$process = new Process("ls -la");
$process->run(); // this can block

if (!$process->isSuccessful()) {
    throw new ProcessFailedException($process);
}

print $process->getOutput();
```
> This is from the [documentation](https://symfony.com/doc/current/components/process.html).  

Best of all, this library automatically escapes command input, making it slightly more secure than the alternative. You should still avoid dynamic commands and/or commands composed of user input.

You can install Symfony Process with:

```sh
composer require symfony/process
```

## Using Doorman

Over time, I’ve built these ideas into a reusable library, called [Doorman](https://github.com/asyncphp/doorman). Despite the [wide range of functionality](https://github.com/asyncphp/doorman/blob/master/docs/en/introduction.md), it is the same idea:

```php
use AsyncPHP\Doorman\Manager\ProcessManager;
use AsyncPHP\Doorman\Task\ProcessCallbackTask;

$task1 = new ProcessCallbackTask(function () {
    print “in task 1”;
});

$task2 = new ProcessCallbackTask(function () {
    print “in task 2”;
});

$manager = new ProcessManager();

$manager->addTask($task1);
$manager->addTask($task2);

while ($manager->tick()) {
    usleep(250);
}
```
> This is from the [readme](https://github.com/asyncphp/doorman/blob/master/readme.md).

I designed Doorman to work well when used with an event loop. We’re going to learn more about event loops in Chapter 9, but for now, you can think of them as better infinite loops. Each time the loop body runs, we `tick` the manager. This checks whether processes started in the background are still running. If all the processes have completed, `tick` returns false, letting us know we can stop waiting for child processes to finish.

We need to wait for this because ending the parent process too soon will also end the child processes before they are finished. This means, although we can run multiple child processes in parallel, we are blocked until they have all finished.

You can install Doorman with:

```sh
composer require asyncphp/doorman
```

## Making It Work

Helpful Robot is all about checking and reporting on the quality of code. That involves quite a few blocking actions. The first step is to clone the repository we’d like to inspect:

```php
function usage() {
    print "Usage: BASE_PATH=path/to/project php clone.php <user/repo>" . PHP_EOL;
    exit;
}

$base = getenv("BASE_PATH");

if (!file_exists($base) || empty($argv[1]) || trim($argv[1]) === "/") {
    usage();
}

$repo = escapeshellarg($argv[1]);

passthru("rm -rf {$base}/repos/{$repo}");
passthru("git clone git@github.com:{$repo}.git {$base}/repos/{$repo}");
```
> This is from `tasks/clone.php`.  

We can’t do any inspections until we have a working copy of the code, so `passthru` is blocking out of necessity. Something has to call this, though. In a traditional application, that might look like:

```php
function usage() {
    print "Usage: BASE_PATH=path/to/project php inspect.php <user/repo>" . PHP_EOL;
    exit;
}

$base = getenv("BASE_PATH");

if (!file_exists($base) || empty($argv[1]) || trim($argv[1]) === "/") {
    usage();
}

$repo = escapeshellarg($argv[1]);

passthru("php {$base}/tasks/clone.php {$repo}");
```
> This is from `inspect.php`.  

This code is almost exactly the same, but it does offer a way for us to organize additional tasks. Imagine we wanted to check for a license file. We might use code resembling:

```php
// ...check command line parameters

$repo = trim($argv[1]);

$previous = getcwd();
chdir("{$base}/repos/{$repo}");

$files = glob("*");

if (empty(preg_grep("/^license/i", $files))) {
    touch("missing-license.check");
}

chdir($previous);
```
> This is from `tasks/check-license.php`.  

In this task, we fetch a list of files (from the repository we assume is already cloned), and look for any file starting with the name `license`. It’s a naive check, but it demonstrates the need for file system operations. Let’s add another check, this time for [Travis CI](https://travis-ci.org/) integration:

```php
// ...check command line parameters

$previous = getcwd();
chdir("{$base}/repos/{$repo}");

$url = "https://api.travis-ci.org/repos.json?slug={$repo}";

$context = stream_context_create([
    "http" => [
        "follow_location" => true,
        "ignore_errors" => true,
        "timeout" => 5,
    ],
]);

$response = @file_get_contents(
    $url, false, $context
);

$response = json_decode($response, true);

if (empty($response) || empty($response[0]["last_build_number"])) {
    touch("missing-travis.check");
}

chdir($previous);
```
> This is from `tasks/check-travis.php`.  

This new check is making an HTTP request to Travis. It’s a continuous integration service, which runs your codebase tests every time you commit new code to a [GitHub](https://github.com/) repository.

This task performs file system and network operations. On a slow connection, it takes a noticeable amount of time to complete this check. Still, we can add these checks to our initial inspection script:

```php
// ...check command line parameters

passthru("php {$base}/tasks/clone.php {$repo}");
passthru("php {$base}/tasks/check-license.php {$repo}");
passthru("php {$base}/tasks/check-travis.php {$repo}");
```
> This is from `inspect.php`.  

Knowing what we now know—about running tasks in the background—we can run `check-license.php` and `check-travis.php` in parallel:

```php
passthru("php {$base}/tasks/clone.php {$repo}");
// passthru("php {$base}/tasks/check-license.php {$repo}");
// passthru("php {$base}/tasks/check-travis.php {$repo}");

$license = deferWithIdentity(function() use ($base, $repo) {
    exec("php {$base}/tasks/check-license.php {$repo}");
});

$travis = deferWithIdentity(function() use ($base, $repo) {
    exec("php {$base}/tasks/check-travis.php {$repo}");
});

function found($pid) {
    exec("ps -p {$pid}", $output);
    return count($output) > 1;
}

while (true) {
    if (found($license) || found($travis)) {
        print ".";
        usleep(250);
        continue;
    }

    break;
}
```
> This is from `inspect.php`.  

The easiest way to make these checks run in parallel is to wrap them in our `deferWithIdentity` function. It would be cleaner to refactor them into stand-alone classes and execute those directly within the closures, but that'll have to wait until later.

Once we have identifiers for each check, we can enter an infinite loop, until both background processes finish. We can tell approximately when this is by polling the `ps` command with the identifiers we already have.

## Summary

In this chapter, we looked at how standard library functions can help us to execute blocking operations asynchronously. We learned how to run slow tasks as new background processes, and even how to get their identity for further monitoring.

We also built the first few moving parts of Helpful Robot. Applying what we learned about parallel execution, we refactored it to run the slow checks in parallel.

In the next chapter, we'll look at the first extension in our toolbox: Pthreads. It will enable multi-threading and requires less overhead code than we've seen in this chapter.
