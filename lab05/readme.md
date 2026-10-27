# lab04: jenkins + ssh-agent, ci для php

Стенд из двух контейнеров: контроллер Jenkins и SSH-агент с PHP. Контроллер сам ничего не собирает (0 исполнителей на встроенном узле), он подключается к агенту по SSH и запускает на нём pipeline из `lab04/Jenkinsfile`: забрать репозиторий, поставить зависимости через composer, прогнать модульные тесты PHPUnit для маленького проекта `todo-app` (список задач с приоритетами).

Слои:

| слой | что | файл |
|---|---|---|
| секреты | пара ключей агента + `.env` с публичным ключом | `setup.sh` |
| контейнеры | контроллер, агент, тома, сеть | `docker-compose.yml`, `Dockerfile` |
| ci | этапы сборки | `Jenkinsfile` |
| проект | код и тесты | `todo-app/` |

## Схема

```text
браузер
  │  http://localhost:8080
  ▼
jenkins-controller (jenkins/jenkins:lts)          том jenkins_home -> /var/jenkins_home
  │  ssh, ключ jenkins-agent-ssh
  │  сеть jenkins-network (bridge), хост ssh-agent:22
  ▼
ssh-agent (jenkins/ssh-agent:jdk21 + php 8.4 + composer)   том jenkins_agent_volume -> /home/jenkins/agent
  │
  ├─ git checkout          (ветка lab04)
  ├─ composer install      (lab04/todo-app)
  └─ phpunit --testsuite unit
```

| порт | кто | зачем |
|---|---|---|
| 8080 | контроллер | веб-интерфейс |
| 50000 | контроллер | не используется (только для inbound-агентов, здесь агент подключается по SSH) |
| 22 | агент | только внутри `jenkins-network`, наружу не пробрасывается |

## Структура

```text
lab04/
├── .gitignore
├── Dockerfile
├── Jenkinsfile
├── docker-compose.yml
├── readme.md
├── secrets/.gitkeep
├── setup.sh
└── todo-app/
    ├── composer.json
    ├── phpunit.xml
    ├── src/{Priority.php, Task.php, TaskList.php}
    └── tests/Unit/{TaskTest.php, TaskListTest.php}
```

| файл | зачем |
|---|---|
| `setup.sh` | создаёт ключи в `secrets/` (если их ещё нет) и пишет `.env` с `JENKINS_AGENT_SSH_PUBKEY` |
| `.gitignore` | не коммитить ключи, `.env`, `vendor/`, `composer.lock`, кэш phpunit |
| `secrets/.gitkeep` | чтобы папка была в репозитории, сами ключи не коммитятся |
| `docker-compose.yml` | сервисы `jenkins-controller` и `ssh-agent`, тома, сеть |
| `Dockerfile` | образ агента: `jenkins/ssh-agent:jdk21` + php + composer |
| `Jenkinsfile` | pipeline: `Install Dependencies` -> `Test` -> `post` |
| `todo-app/` | проект на PHP с тестами |

## Проект todo-app

Пространство имён `Todo\`, тесты `Todo\Tests\Unit\`, автозагрузка PSR-4 через composer. PHP `>=8.3`, PHPUnit `^12.5`.

| класс | что делает |
|---|---|
| `Priority` | enum: `Low = 1`, `Normal = 2`, `High = 3` |
| `Task` | задача: название (пустое -> `InvalidArgumentException`), приоритет (по умолчанию `Normal`), `isDone()`, `markDone()` |
| `TaskList` | список: `add()` (дубль названия -> `DomainException`), `complete()` (нет задачи -> `DomainException`), `pending()`, `done()`, `byPriority()` (сначала High, внутри приоритета порядок добавления), `count()` (`Countable`) |

| файл | тесты |
|---|---|
| `tests/Unit/TaskTest.php` | `testNewTaskIsNotDone`, `testDefaultPriorityIsNormal`, `testMarkDone`, `testEmptyTitleThrows` |
| `tests/Unit/TaskListTest.php` | `testCount`, `testDuplicateTitleThrows`, `testCompleteMovesTaskToDone`, `testCompleteUnknownThrows`, `testByPriorityHighFirst`, `testPendingKeepsInsertionOrder` |

В `TaskListTest` фикстура `setUp()` кладёт в список 3 задачи разного приоритета, каждый тест начинает с чистого списка. В `phpunit.xml` один набор `unit` (`tests/Unit`), его и запускает pipeline.

Почему нет `composer.lock`: проект учебный, зависимость одна (`phpunit/phpunit ^12.5`), диапазон узкий, поэтому версии подбирает сам composer при первой сборке. Lock создаётся в рабочей папке агента и остаётся там до очистки workspace: во второй сборке composer уже ставит из него (`Nothing to install, update or remove`).

## Настройки

| что | где | значение |
|---|---|---|
| `JENKINS_AGENT_SSH_PUBKEY` | `.env` (создаёт `setup.sh`) | содержимое `secrets/jenkins_agent_ssh_key.pub` |
| php | `Dockerfile`, apt | `php-cli php-xml php-mbstring php-zip` -> PHP 8.4.27 |
| composer | `Dockerfile`, официальный установщик | 2.10.3 в `/usr/local/bin/composer` |
| phpunit | `todo-app/composer.json` | `^12.5` -> 12.5.40 |
| jenkins | `docker-compose.yml` | `jenkins/jenkins:lts` (2.580.1) |

Узел:

| поле | значение |
|---|---|
| Name | `ssh-agent1` |
| Type | Permanent Agent |
| Number of executors | 1 |
| Remote root directory | `/home/jenkins/agent` |
| Labels | `php-agent` |
| Usage | Use this node as much as possible |
| Launch method | Launch agents via SSH |
| Host | `ssh-agent` (имя сервиса в `jenkins-network`) |
| Credentials | `jenkins-agent-ssh` (jenkins) |
| Host Key Verification Strategy | Manually trusted key Verification Strategy, галочка «Require manual verification of initial connection» снята |
| Port | 22 (Advanced, по умолчанию) |
| Availability | Keep this agent online as much as possible |

Job:

| поле | значение |
|---|---|
| имя | `todo-app-ci` |
| тип | Pipeline |
| Definition | Pipeline script from SCM |
| SCM | Git |
| Repository URL | `https://github.com/raetskijr/automation.git` (публичный, credentials не нужны) |
| Branch Specifier | `*/lab04` |
| Script Path | `lab04/Jenkinsfile` |

## Запуск

1. ключи и `.env`:

   ```text
   $ ./setup.sh
   Generating public/private ed25519 key pair.
   Your identification has been saved in secrets/jenkins_agent_ssh_key
   Your public key has been saved in secrets/jenkins_agent_ssh_key.pub
   ...
   [setup] .env готов, ключ: secrets/jenkins_agent_ssh_key
   ```

   То же вручную, как в задании:

   ```bash
   mkdir -p secrets && cd secrets
   ssh-keygen -f jenkins_agent_ssh_key        # пароль пустой
   cd ..
   echo "JENKINS_AGENT_SSH_PUBKEY=$(cat secrets/jenkins_agent_ssh_key.pub)" > .env
   ```

2. освободить порт 8080: его держит `php_apache` из lab02prep.

   ```bash
   docker compose -p lab02prep stop
   ```

3. поднять стенд:

   ```bash
   docker compose up -d --build
   ```

4. проверить:

   ```bash
   docker compose ps
   ```

   Должны быть `jenkins-controller` (порты 8080, 50000) и `ssh-agent` (22/tcp), оба `Up`.

## Jenkins

### контроллер

1. пароль разблокировки:

   ```bash
   docker compose exec jenkins-controller cat /var/jenkins_home/secrets/initialAdminPassword
   ```

2. http://localhost:8080 -> вставить пароль.
3. Customize Jenkins -> **Select plugins to install** -> **None**, затем отметить только:

   | плагин | id | зачем |
   |---|---|---|
   | Pipeline | `workflow-aggregator` | job типа Pipeline и Jenkinsfile |
   | Git | `git` | checkout репозитория |
   | SSH Build Agents | `ssh-slaves` | запуск агента по SSH |
   | Credentials | `credentials` | хранение ключа |

   С зависимостями получилось 59 плагинов.
4. создать администратора, Jenkins URL оставить `http://localhost:8080/`.
5. Manage Jenkins -> Nodes -> Built-In Node -> Configure -> **Number of executors = 0**. Теперь любая сборка может идти только на агенте.
6. Manage Jenkins -> Plugins -> Installed: проверить, что **SSH Build Agents** есть.

### агент

1. Manage Jenkins -> Credentials -> System -> Global credentials -> Add Credentials:

   | поле | значение |
   |---|---|
   | Kind | SSH Username with private key |
   | ID | `jenkins-agent-ssh` |
   | Username | `jenkins` |
   | Private Key | Enter directly -> содержимое `secrets/jenkins_agent_ssh_key` |
   | Passphrase | пусто |

2. Manage Jenkins -> Nodes -> New Node -> `ssh-agent1`, Permanent Agent -> поля из таблицы «Узел» выше.
3. Save -> узел подключается сам, в логе узла (`ssh-agent1` -> Log) в конце `Agent successfully connected and online`.

Стратегия проверки ключа хоста:

| вариант | плюс | минус |
|---|---|---|
| Non verifying | никогда не мешает | ключ хоста не проверяется вообще |
| Known hosts file | строгая проверка | нужно заранее положить ключ агента в `known_hosts` контроллера, а агент генерирует новые ключи хоста при каждом пересоздании |
| **Manually trusted (выбран)** | первый ключ доверяется сам и запоминается в узле, дальше проверяется | после пересоздания контейнера `ssh-agent` новый ключ надо подтвердить вручную (см. «Проблемы») |

Выбран manually trusted: проверка есть, но ничего не надо готовить заранее. Подмену агента видно сразу, узел не подключится, пока ключ не подтвердят.

### pipeline

1. New Item -> `todo-app-ci` -> Pipeline.
2. Pipeline -> Definition: Pipeline script from SCM, поля из таблицы «Job» выше.
3. Save -> Build Now.

Что делает `Jenkinsfile`:

| блок | что |
|---|---|
| `agent { label 'php-agent' }` | вся сборка на узле с меткой `php-agent`, то есть на `ssh-agent1` |
| `options` | `buildDiscarder` (10 последних сборок), `disableConcurrentBuilds` |
| `Install Dependencies` | версии php/composer в лог, `composer install --no-interaction --no-progress` в `lab04/todo-app` |
| `Test` | `vendor/bin/phpunit --testsuite unit --colors=never` в `lab04/todo-app` |
| `post` | `always` / `success` / `failure`: сообщения из шаблона задания |

## Проверка

Сборка #2 (#1 упала, см. «Проблемы», п. 5). Вывод сокращён: строки git-checkout и служебные `[Pipeline]` частично убраны (`...`).

```text
Started by user admin
...
[Pipeline] Start of Pipeline
[Pipeline] node
Running on ssh-agent1 in /home/jenkins/agent/workspace/todo-app-ci
[Pipeline] {
[Pipeline] stage
[Pipeline] { (Declarative: Checkout SCM)
[Pipeline] checkout
...
 > git --version # 'git version 2.47.3'
...
Commit message: "lab04: jenkinsfile"
...
[Pipeline] { (Install Dependencies)
[Pipeline] echo
Preparing project...
[Pipeline] dir
Running in /home/jenkins/agent/workspace/todo-app-ci/lab04/todo-app
[Pipeline] {
[Pipeline] sh
+ php -v
+ head -n 1
PHP 8.4.27 (cli) (built: Oct 21 2026 07:53:46) (NTS)
+ composer --version
Composer version 2.10.3 2026-08-27 13:34:23
PHP version 8.4.27 (/usr/bin/php8.4)
Run the "diagnose" command to get more detailed diagnostics output.
[Pipeline] sh
+ composer install --no-interaction --no-progress
Installing dependencies from lock file (including require-dev)
Verifying lock file contents can be installed on current platform.
Nothing to install, update or remove
Generating autoload files
23 packages you are using are looking for funding.
Use the `composer fund` command to find out more!
...
[Pipeline] { (Test)
[Pipeline] echo
Running tests...
[Pipeline] dir
Running in /home/jenkins/agent/workspace/todo-app-ci/lab04/todo-app
[Pipeline] {
[Pipeline] sh
+ vendor/bin/phpunit --testsuite unit --colors=never
PHPUnit 12.5.40 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.27
Configuration: /home/jenkins/agent/workspace/todo-app-ci/lab04/todo-app/phpunit.xml

..........                                                        10 / 10 (100%)

Time: 00:00.001, Memory: 14.00 MB

OK (10 tests, 12 assertions)
...
[Pipeline] { (Declarative: Post Actions)
[Pipeline] echo
Pipeline completed.
[Pipeline] echo
All stages completed successfully!
...
[Pipeline] End of Pipeline
Finished: SUCCESS
```

В сборке #1 composer ставил зависимости с нуля (lock ещё не было):

```text
+ composer install --no-interaction --no-progress
Composer is operating significantly slower than normal because you do not have the PHP curl extension enabled.
No composer.lock file present. Updating dependencies to latest instead of installing from lock file. See https://getcomposer.org/install for more information.
Loading composer repositories with package information
Updating dependencies
Lock file operations: 25 installs, 0 updates, 0 removals
...
  - Locking phpunit/phpunit (12.5.40)
...
Writing lock file
Installing dependencies from lock file (including require-dev)
Package operations: 25 installs, 0 updates, 0 removals
As there is no 'unzip' nor '7z' command installed zip files are being unpacked using the PHP zip extension.
...
  - Installing phpunit/phpunit (12.5.40): Extracting archive
...
Generating autoload files
```

Два предупреждения composer (нет `php-curl`, нет `unzip`) сборке не мешают: архивы распаковывает `php-zip`, скачивание идёт без curl, на 25 пакетах это незаметно.

## Вопросы

### Преимущества Jenkins для автоматизации DevOps

| плюс | что даёт | в этой лабе |
|---|---|---|
| история сборок | у каждого запуска свой лог, статус и номер, видно, что сломалось и когда | сборка #1 упала, #2 прошла |
| бесплатный self-hosted | работает на своём железе, без внешнего сервиса и лимитов | весь стенд локально в docker |
| агенты | сборки идут не на контроллере, у каждого агента своё окружение | `ssh-agent` с php и composer, на контроллере 0 исполнителей |
| pipeline as code | сборка описана в файле и живёт в git рядом с кодом, её видно в истории и в ревью | `lab04/Jenkinsfile`, job только указывает на него |
| плагины | под каждую задачу свой плагин, ядро остаётся маленьким | тут выбраны четыре: `ssh-slaves`, `git`, `workflow-aggregator`, `credentials` |

### Какие ещё бывают агенты Jenkins

| тип | кто подключается | пример |
|---|---|---|
| встроенный узел (built-in node) | никто, сборка идёт в процессе контроллера | исполнители Built-In Node (здесь выставлено 0) |
| постоянный SSH-агент | контроллер -> агент по SSH | `ssh-agent1` в этой лабе (`jenkins/ssh-agent`) |
| inbound-агент | агент сам подключается к контроллеру на порт 50000 | образ `jenkins/inbound-agent`, для агента за NAT / без входящего ssh |
| облачный агент: Docker / Kubernetes | контроллер создаёт агента на время сборки и удаляет после | плагины Docker, Kubernetes (pod на каждую сборку) |
| контейнер внутри pipeline | шаг сборки запускается в контейнере на уже имеющемся агенте | `agent { docker { image 'php:8.4-cli' } }` (нужен плагин Docker Pipeline и docker на агенте) |

### Проблемы при настройке и решения

| # | проблема | симптом | решение |
|---|---|---|---|
| 1 | порт 8080 занят | `Bind for 0.0.0.0:8080 failed: port is already allocated`, порт занят `php_apache` из lab02prep | `docker compose -p lab02prep stop` на время работы, потом `start` |
| 2 | нет `.env` | compose: `The "JENKINS_AGENT_SSH_PUBKEY" variable is not set. Defaulting to a blank string.`, у агента нет `authorized_keys`, в логе узла `ERROR: Server rejected the 1 private key(s) for jenkins (credentialId:jenkins-agent-ssh/method:publickey)` и `Authentication failed.` | `./setup.sh`, затем `docker compose up -d` (агент пересоздаётся с ключом) |
| 3 | composer без zip | образ без `php-zip`: `Failed to download staabm/side-effects-detector from dist: The zip extension and unzip/7z commands are both missing, skipping.` и `Source fallback is disabled.`, `composer install` завершается ошибкой, `vendor/` нет | `php-zip` в `Dockerfile` (отдельный `unzip` не нужен) |
| 4 | новый ключ хоста после пересоздания агента | `setup-sshd` генерирует ключи хоста при каждом старте нового контейнера. В логе узла `WARNING: The SSH key for this host is not currently trusted. Connections will be denied until this new key is authorised.`, затем `Key exchange was not finished, connection is closed.` и повторы каждые 15 секунд | на странице узла появляется **Trust SSH Host Key**: «Do you want to replace the SSH Host Key with fingerprint ... with the SSH Host Key with fingerprint ...?» -> Yes. Следующая попытка: `SSH host key matches key seen previously for this host. Connection will be allowed.` |
| 5 | `phpunit.xml` с баннерами `<!-- --- ... --- -->` | сборка #1 шла с ранней версией `phpunit.xml`, ещё до того, как он попал в репозиторий в нынешнем виде: `Double hyphen within comment` и `Comment must not contain '--' (double-hyphen)`, `ERROR: script returned exit code 2`, `Finished: FAILURE` | в XML внутри комментария `--` нельзя, баннеры там переписаны как `<!-- [ наборы ] -->`, в репозиторий попала уже исправленная версия |

Лог узла для п. 2 и п. 4 (сокращён):

```text
[10/26/26 07:41:25] [SSH] Opening SSH connection to ssh-agent:22.
[10/26/26 07:41:25] [SSH] The SSH key with fingerprint SHA256:zgt0Q33btH7u6glSrRgv4iV2TLFe4GShEg0SYYsraow= has been automatically trusted for connections to this machine.
ERROR: Server rejected the 1 private key(s) for jenkins (credentialId:jenkins-agent-ssh/method:publickey)
[10/26/26 07:41:25] [SSH] Authentication failed.
...
[10/26/26 07:41:44] [SSH] Opening SSH connection to ssh-agent:22.
[10/26/26 07:41:44] [SSH] WARNING: The SSH key for this host is not currently trusted. Connections will be denied until this new key is authorised.
Key exchange was not finished, connection is closed.
SSH Connection failed with IOException: "Key exchange was not finished, connection is closed.", retrying in 15 seconds. There are 10 more retries left.
...
[10/26/26 07:42:14] [SSH] SSH host key matches key seen previously for this host. Connection will be allowed.
[10/26/26 07:42:14] [SSH] Authentication successful.
...
Agent successfully connected and online
```

Первый ключ хоста был принят автоматически (флажок ручной проверки снят), но ключ из `.env` был пустой, поэтому отказ произошёл на этапе аутентификации. После `setup.sh` агент пересоздан, ключ хоста у него уже другой, и узел ждал подтверждения.

## Остановка

```bash
docker compose down -v --rmi local   # всё: контейнеры, тома, образ агента
docker compose -p lab02prep start    # вернуть php_apache на 8080
```
