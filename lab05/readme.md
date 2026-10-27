# lab05: ansible + jenkins, test-server для todo-app

## Описание проекта

Состав стенда показан в разделе «Схема», роли контейнеров в таблице «Сервисы compose». Проект тот же, что в lab04: `todo-app`, список задач с приоритетами (`Todo\Priority`, `Todo\Task`, `Todo\TaskList`). В lab05 к нему добавлена страница: форма на 5 задач и доска из двух колонок «в работе» / «готово».

Три pipeline работают по цепочке:

| задание | что делает | где |
|---|---|---|
| `todo-app-ci` | composer + PHPUnit + отчёт JUnit | `ssh-agent` |
| `test-server-ansible` | ставит на test-server Apache, PHP и виртуальный хост | `ansible-agent` |
| `todo-app-cd` | копирует todo-app на test-server и собирает автозагрузку | `ansible-agent` |

Принцип стенда: образы общие и без секретов. Ключи приходят через compose при запуске, настройки сайта лежат в `group_vars`, у каждой стадии выкладки свой плейбук.

## Схема

```text
браузер ── http://localhost:8080 ──> jenkins-controller (jenkins/jenkins:lts)
                                        │
                  ┌─────────────────────┴──────────────────────┐
                  │ ssh: jenkins_agent_ssh_key                 │ ssh: jenkins_to_ansible
                  ▼                                            ▼
            ssh-agent                                     ansible-agent
            (php 8.4, composer)                           (ubuntu 24.04, ansible, java 21)
            метка php-agent                               метка ansible-runner
                                                               │
                                                               │ ssh: ansible_to_test (пользователь ansible)
                                                               ▼
браузер ── http://localhost:8090 ──────────────────────>  test-server
                                                          (ubuntu 24.04, apache + php 8.3)

все четыре контейнера: сеть jenkins-network (bridge)

цепочка заданий:  todo-app-ci  ->  test-server-ansible  ->  todo-app-cd
                  (тесты)          (сервер)                 (выкладка)
```

| порт на хосте | контейнер | зачем |
|---|---|---|
| 8080 | jenkins-controller | веб-интерфейс Jenkins |
| 50000 | jenkins-controller | входящие агенты, здесь не используется |
| 8090 | test-server (80) | сайт todo-app |

Порт 22 у агентов и у test-server наружу не пробрасывается, к ним ходят только по `jenkins-network`.

## Структура

```text
lab05/
├── .gitignore
├── setup.sh
├── compose.yaml
├── Dockerfile.ssh_agent
├── Dockerfile.ansible_agent
├── Dockerfile.test_server
├── config/
│   ├── sshd/lab05.conf
│   └── sudoers/ansible
├── ansible/
│   ├── ansible.cfg
│   ├── hosts.ini
│   ├── group_vars/stand.yml
│   ├── setup_test_server.yml
│   ├── tasks/{packages.yml, php.yml, vhost.yml}
│   ├── templates/{vhost.conf.j2, php-app.ini.j2}
│   └── deploy/{copy.yml, configure.yml}
├── pipelines/
│   ├── php_build_and_test_pipeline.groovy
│   ├── ansible_setup_pipeline.groovy
│   └── php_deploy_pipeline.groovy
├── todo-app/
│   ├── composer.json, phpunit.xml
│   ├── src/{Priority.php, Task.php, TaskList.php}
│   ├── src/Web/TaskForm.php
│   ├── tests/Unit/{TaskTest.php, TaskListTest.php, TaskFormTest.php}
│   ├── public/{index.php, style.css}
│   └── views/board.php
├── secrets/.gitkeep
└── readme.md
```

| файл | зачем |
|---|---|
| `setup.sh` | создаёт три пары ключей в `secrets/` (если их нет) и `.env` с публичным ключом для ssh-agent |
| `.gitignore` | не коммитить ключи, `.env`, `vendor/`, `composer.lock`, кэш PHPUnit, `reports/` |
| `compose.yaml` | 4 сервиса, секрет, 3 тома, сеть |
| `Dockerfile.ssh_agent` | агент php из lab04 без изменений (только новое имя файла) |
| `Dockerfile.ansible_agent` | Ubuntu + Ansible + Java 21 + sshd, агент Jenkins |
| `Dockerfile.test_server` | Ubuntu + sshd + sudo + python3, пользователь `ansible` |
| `config/sshd/lab05.conf` | общий для двух образов: вход только по ключу, ключи в `/etc/ssh/keys/<пользователь>.pub` |
| `config/sudoers/ansible` | sudo без пароля для `ansible` (нужно для become) |
| `ansible/` | настройка сервера и выкладка |
| `pipelines/` | три pipeline |
| `todo-app/` | проект, тесты и страница |

## Ключи

| пара | откуда -> куда | публичная часть | закрытая часть |
|---|---|---|---|
| `jenkins_agent_ssh_key` | Jenkins -> ssh-agent | `.env`, переменная `JENKINS_AGENT_SSH_PUBKEY` (как в lab04) | credential `jenkins-agent-ssh` |
| `jenkins_to_ansible` | Jenkins -> ansible-agent | bind mount `./secrets/jenkins_to_ansible.pub` -> `/etc/ssh/keys/jenkins.pub` | credential `jenkins-ansible-ssh` |
| `ansible_to_test` | ansible-agent -> test-server | bind mount `./secrets/ansible_to_test.pub` -> `/etc/ssh/keys/ansible.pub` | compose secret -> `/run/secrets/ansible_to_test` в ansible-agent |

Все ключи ed25519 без пароля, их делает `setup.sh`. Ни один ключ не копируется в образ: образ можно пересобрать или отдать кому угодно, а смена ключей не требует сборки, хватает `docker compose up -d`.

## Настройки

Сервисы compose:

| сервис | образ | порты | тома | ключи |
|---|---|---|---|---|
| `jenkins-controller` | `jenkins/jenkins:lts` | 8080, 50000 | `jenkins_home` | хранит закрытые ключи 1 и 2 как credentials |
| `ssh-agent` | `Dockerfile.ssh_agent` | нет | `jenkins_agent_volume` | `JENKINS_AGENT_SSH_PUBKEY` из `.env` |
| `ansible-agent` | `Dockerfile.ansible_agent` | нет | `ansible_agent_work` | `jenkins.pub` (bind mount), секрет `ansible_to_test` |
| `test-server` | `Dockerfile.test_server` | 8090 -> 80 | нет | `ansible.pub` (bind mount) |

Версия Ubuntu для двух новых образов записана один раз: якорь `x-ubuntu` в `compose.yaml` передаётся в `build.args`, в Dockerfile это `ARG UBUNTU_VERSION`.

Узлы Jenkins:

| узел | метка | хост | ключ | проверка ключа хоста |
|---|---|---|---|---|
| `ssh-agent1` | `php-agent` | `ssh-agent` | `jenkins-agent-ssh` | Manually trusted key Verification Strategy |
| `ansible-node` | `ansible-runner` | `ansible-agent` | `jenkins-ansible-ssh` | Manually trusted key Verification Strategy |

У обоих: Remote root directory `/home/jenkins/agent`, 1 исполнитель, Launch agents via SSH. У Built-In Node 0 исполнителей.

Credentials (Global, тип SSH Username with private key, Username `jenkins`):

| ID | содержимое |
|---|---|
| `jenkins-agent-ssh` | `secrets/jenkins_agent_ssh_key` |
| `jenkins-ansible-ssh` | `secrets/jenkins_to_ansible` |

Задания (все: Pipeline script from SCM, Git, `https://github.com/raetskijr/automation.git`, ветка `*/main`):

| задание | Script Path | агент | стадии |
|---|---|---|---|
| `todo-app-ci` | `lab05/pipelines/php_build_and_test_pipeline.groovy` | `php-agent` | clone, deps, test, report |
| `test-server-ansible` | `lab05/pipelines/ansible_setup_pipeline.groovy` | `ansible-runner` | clone, playbook |
| `todo-app-cd` | `lab05/pipelines/php_deploy_pipeline.groovy` | `ansible-runner` | clone, copy, configure |

## Запуск

```bash
cd lab05
./setup.sh                       # ключи + .env
docker compose up -d --build     # 4 контейнера
docker compose ps
docker compose exec jenkins-controller cat /var/jenkins_home/secrets/initialAdminPassword
```

Порт 8080 должен быть свободен. У меня его держит `php_apache` из lab02prep, на время работы: `docker compose -p lab02prep stop`.

## Jenkins

### контроллер

1. http://localhost:8080 -> пароль из `initialAdminPassword`.
2. Customize Jenkins -> Select plugins to install -> None, затем отметить:

   | плагин | id | зачем |
   |---|---|---|
   | Docker | `docker-plugin` | требование задания (облачные агенты в контейнерах, здесь не настраивался) |
   | Docker Pipeline | `docker-workflow` | требование задания (`docker` в pipeline, здесь не используется) |
   | GitHub Integration | `github-pullrequest` | требование задания (запуск заданий по webhook из GitHub) |
   | SSH Agent | `ssh-agent` | требование задания (шаг `sshagent` в pipeline) |
   | SSH Build Agents | `ssh-slaves` | запуск обоих агентов по SSH |
   | Git | `git` | шаг `git` и Pipeline script from SCM |
   | Pipeline | `workflow-aggregator` | задания вида Pipeline |
   | JUnit | `junit` | шаг `junit` в стадии report |

   С зависимостями получилось 86 плагинов.
3. Создать администратора, Jenkins URL оставить.
4. Manage Jenkins -> Nodes -> Built-In Node -> Configure -> Number of executors = 0.

### агенты

`ssh-agent1` настроен так же, как в lab04: credential `jenkins-agent-ssh`, узел `ssh-agent1`, метка `php-agent`, хост `ssh-agent`.

`ansible-node` новый:

1. Manage Jenkins -> Credentials -> Global -> Add Credentials: SSH Username with private key, ID `jenkins-ansible-ssh`, Username `jenkins`, Private Key -> Enter directly -> `secrets/jenkins_to_ansible`.
2. Manage Jenkins -> Nodes -> New Node -> `ansible-node`, Permanent Agent, поля из таблицы «Узлы Jenkins».
3. Save. Первый ключ хоста принимается сам, в логе узла:

   ```text
   [SSH] Opening SSH connection to ansible-agent:22.
   [SSH] The SSH key with fingerprint SHA256:YVUhlFUpsX3k3AJDX+IALNSwRkcBMJNJ87ki3k9SMy4= has been automatically trusted for connections to this machine.
   [SSH] Authentication successful.
   ...
   Agent successfully connected and online
   ```

Что внутри `Dockerfile.ansible_agent`:

| блок | что | зачем |
|---|---|---|
| база | `ubuntu:${UBUNTU_VERSION}`, по умолчанию 24.04 | требование задания: Ubuntu |
| пакеты | `openssh-server`, `openjdk-21-jre-headless` | Jenkins подключается по SSH и запускает `remoting.jar` на Java 21 (как у контроллера) |
| | `git` | стадия clone |
| | `openssh-client`, `rsync` | Ansible ходит на test-server по SSH, `synchronize` запускает `rsync` и здесь |
| | `software-properties-common`, `gpg-agent` | нужны для `add-apt-repository` |
| ansible | PPA `ppa:ansible/ansible`: `ansible` 14.5.0, `ansible-core` 2.21.5 | в apt Ubuntu 24.04 версия старее (core 2.16), в полном пакете уже есть `ansible.posix` для `synchronize` |
| пользователь | `jenkins`, каталог `/home/jenkins/agent` | под ним работает агент |
| sshd | `config/sshd/lab05.conf`, `/run/sshd`, `/etc/ssh/keys` | ключ `jenkins.pub` монтирует compose |
| запуск | `sshd -D -e` | sshd в переднем плане, лог в `docker compose logs` |

### pipelines

Общее у всех трёх:

| блок | что |
|---|---|
| `options` | `buildDiscarder` (10 сборок), `disableConcurrentBuilds`, `skipDefaultCheckout` (клонирует стадия clone) |
| `parameters` | `REPO_URL` (по умолчанию GitHub), `BRANCH` (по умолчанию `main`) |
| стадия `clone` | `git url: params.REPO_URL, branch: params.BRANCH` |

`php_build_and_test_pipeline.groovy` (это `Jenkinsfile` из lab04, перенесён и дополнен):

| стадия | команда |
|---|---|
| clone | `git ...` |
| deps | `php -v \| head -n 1 && composer --version`, `composer install --no-interaction --no-progress` |
| test | `vendor/bin/phpunit --testsuite unit --colors=never --log-junit reports/junit.xml` |
| report | `junit "lab05/todo-app/reports/junit.xml"` |

`ansible_setup_pipeline.groovy`:

| стадия | команда |
|---|---|
| clone | `git ...` |
| playbook | в `lab05/ansible`: `ansible --version \| head -n 1`, `ansible-playbook setup_test_server.yml` |

`php_deploy_pipeline.groovy`:

| стадия | команда |
|---|---|
| clone | `git ...` |
| copy | в `lab05/ansible`: `ansible-playbook deploy/copy.yml` |
| configure | в `lab05/ansible`: `ansible-playbook deploy/configure.yml` |

Флагов у `ansible-playbook` нет: инвентарь берётся из `ansible.cfg`, ключ и become из `group_vars/stand.yml`.

Создание задания (для каждого из трёх): New Item -> имя -> Pipeline -> Definition: Pipeline script from SCM -> поля из таблицы «Задания» -> Save -> Build Now. Блок `parameters` Jenkins читает только при первом запуске, поэтому первая сборка идёт со значениями по умолчанию, дальше появляется Build with Parameters.

## Тестовый сервер

| что | как |
|---|---|
| база | `ubuntu:${UBUNTU_VERSION}`, 24.04 (PHP 8.3, todo-app требует `php >=8.3`) |
| пакеты в образе | `openssh-server`, `sudo`, `python3`: только то, что нужно Ansible для входа |
| пользователь | `ansible` (`useradd -m`), пароля нет, вход только по ключу |
| sudo | `config/sudoers/ansible` -> `/etc/sudoers.d/ansible`, права 0440: `ansible ALL=(ALL) NOPASSWD:ALL` |
| sshd | `config/sshd/lab05.conf` -> `/etc/ssh/sshd_config.d/`: `PasswordAuthentication no`, `KbdInteractiveAuthentication no`, `PermitRootLogin no`, `AuthorizedKeysFile /etc/ssh/keys/%u.pub` |
| ключ | `./secrets/ansible_to_test.pub` -> `/etc/ssh/keys/ansible.pub` (только чтение) |
| порт | `8090:80` |
| Apache, PHP, composer, rsync | в образе их нет, ставит Ansible |

`AuthorizedKeysFile /etc/ssh/keys/%u.pub` один на оба образа: на ansible-agent это `jenkins.pub`, на test-server `ansible.pub`. Домашние каталоги пользователей не трогаются.

Проверка:

```bash
docker compose exec test-server id ansible
# uid=1001(ansible) gid=1001(ansible) groups=1001(ansible)
docker compose exec test-server ls -l /etc/ssh/keys
# -rw-r--r-- 1 root root  101 ... ansible.pub
```

## Ansible

Роль не используется: задачи разложены по файлам и подключаются через `import_tasks`, этого хватает для одного сервера.

`ansible.cfg` (читается из текущего каталога, поэтому pipeline заходит в `lab05/ansible`):

| строка | смысл |
|---|---|
| `inventory = hosts.ini` | инвентарь без `-i` |
| `ssh_args = -C -o ControlMaster=auto -o ControlPersist=60s` | значения Ansible по умолчанию, при своём `ssh_args` их надо повторить |
| `-o StrictHostKeyChecking=accept-new` | ключ test-server записывается при первом входе, изменённый ключ отклоняется |

`hosts.ini`: группа `stand`, в ней `test-server ansible_user=ansible`. Имя хоста = имя сервиса в `jenkins-network`.

`group_vars/stand.yml`:

| переменная | значение | где используется |
|---|---|---|
| `ansible_ssh_private_key_file` | `/run/secrets/ansible_to_test` | все плейбуки |
| `ansible_become` | `true` | все плейбуки (apt, конфиги, `/srv`) |
| `ansible_python_interpreter` | `/usr/bin/python3` | все плейбуки |
| `php_version` | `"8.3"` | `php_packages`, путь conf.d в `tasks/php.yml` |
| `php_packages` | `php8.3-cli`, `libapache2-mod-php8.3`, `php8.3-mbstring` | `tasks/packages.yml` |
| `tool_packages` | `composer`, `unzip`, `rsync` | `tasks/packages.yml` |
| `site_name` | `todo-app` | имена конфига vhost, логов, ini-файла |
| `site_dir` | `/srv/todo-app` | vhost, `deploy/copy.yml`, `deploy/configure.yml` |
| `site_src` | `{{ inventory_dir }}/../todo-app/` | `deploy/copy.yml` |

`setup_test_server.yml`:

| файл задач | задача | модуль |
|---|---|---|
| `tasks/packages.yml` | `apache: пакеты` | `ansible.builtin.apt` (`update_cache`, `cache_valid_time: 3600`) |
| | `php: пакеты` | `ansible.builtin.apt` (`php_packages + tool_packages`) |
| | `apache: запуск` | `ansible.builtin.service` (`state: started`) |
| `tasks/php.yml` | `php: настройки` | `ansible.builtin.template` -> `/etc/php/8.3/apache2/conf.d/90-todo-app.ini` |
| `tasks/vhost.yml` | `vhost: каталог` | `ansible.builtin.file` (`/srv/todo-app/public`) |
| | `vhost: конфиг` | `ansible.builtin.template` -> `/etc/apache2/sites-available/todo-app.conf` |
| | `vhost: включить` | `ansible.builtin.file` (`state: link` в `sites-enabled`) |
| | `vhost: убрать 000-default` | `ansible.builtin.file` (`state: absent`) |

Ссылки в `sites-enabled` делает модуль `file`: результат тот же, что у `a2ensite todo-app` и `a2dissite 000-default`, но задача сама идемпотентна. Модуль PHP для Apache включает пакет `libapache2-mod-php8.3`.

Handler один: `apache: reload` (`ansible.builtin.service`, `state: reloaded`). Его вызывают `php: настройки`, `vhost: конфиг`, `vhost: включить`, `vhost: убрать 000-default`. Systemd в контейнере нет, `service` работает через `/etc/init.d/apache2`.

Шаблоны:

| шаблон | что внутри |
|---|---|
| `vhost.conf.j2` | `<VirtualHost *:80>`, `DocumentRoot /srv/todo-app/public`, `<Directory>` с `Require all granted` и `AllowOverride None`, логи `todo-app-error.log` / `todo-app-access.log` |
| `php-app.ini.j2` | `display_errors = Off`, `date.timezone = Europe/Chisinau`, `memory_limit = 64M` |

Настройки PHP лежат отдельным файлом в `conf.d`, сам `php.ini` не меняется.

`deploy/copy.yml` (стадия copy):

| задача | модуль | параметры |
|---|---|---|
| `deploy: rsync` | `ansible.posix.synchronize` | `src: site_src`, `dest: /srv/todo-app/`, `delete: true`, исключены `tests/`, `vendor/`, `.phpunit.cache/`, `phpunit.xml`, `composer.lock`, `reports/` |

`deploy/configure.yml` (стадия configure):

| задача | модуль | что |
|---|---|---|
| `deploy: автозагрузка` | `ansible.builtin.command` | `composer dump-autoload --no-dev --classmap-authoritative` в `/srv/todo-app`, `COMPOSER_ALLOW_SUPERUSER=1` |

Зависимостей для работы у todo-app нет (только PHP), поэтому вместо `composer install` достаточно `dump-autoload`: `vendor/autoload.php` появляется без сети и без `composer.lock`. Права на файлы отдельно не меняются: Apache их читает и так (каталоги 755, файлы 644).

## Страница todo-app

| файл | роль |
|---|---|
| `src/Web/TaskForm.php` | `TaskForm::fromRows(array $rows): TaskList`: пустые строки пропускаются, неизвестный приоритет -> `Normal`, дубль названия пропускается, `done = 1` -> задача готова |
| `public/index.php` | контроллер: POST -> `TaskForm::fromRows($_POST['rows'])`, GET -> пустой список, дальше `views/board.php` |
| `views/board.php` | разметка: шапка со счётчиками, форма на 5 строк, доска «в работе» (порядок `byPriority()`, метка приоритета) и «готово» (зачёркнуто); весь вывод через `htmlspecialchars` |
| `public/style.css` | цвета в переменных CSS, доска `grid` в две колонки, без JS |

Новые тесты в `tests/Unit/TaskFormTest.php`: `testEmptyRowsAreSkipped`, `testDoneFlagAndPriorityMapped`. Всего 12 тестов.

## Проверка

Вывод сокращён (`...`), строки git с путями убраны.

`todo-app-ci` #2:

```text
Running on ssh-agent1 in /home/jenkins/agent/workspace/todo-app-ci
...
Commit message: "lab05: pipelines/*.groovy"
...
+ php -v
+ head -n 1
PHP 8.4.27 (cli) (built: Oct 21 2026 07:53:46) (NTS)
+ composer --version
Composer version 2.10.3 2026-08-27 13:34:23
...
+ composer install --no-interaction --no-progress
...
Lock file operations: 25 installs, 0 updates, 0 removals
...
Generating autoload files
...
+ vendor/bin/phpunit --testsuite unit --colors=never --log-junit reports/junit.xml
PHPUnit 12.5.42 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.27
Configuration: /home/jenkins/agent/workspace/todo-app-ci/lab05/todo-app/phpunit.xml

............                                                      12 / 12 (100%)

Time: 00:00.011, Memory: 14.00 MB

OK (12 tests, 17 assertions)
[Pipeline] junit
Recording test results
...
All stages completed successfully!
Finished: SUCCESS
```

В Test Result сборки: 12 passed, 0 failed, 0 skipped.

`test-server-ansible` #2 (сервер только что создан, на нём нет Apache):

```text
+ ansible --version
+ head -n 1
ansible [core 2.21.5]
+ ansible-playbook setup_test_server.yml

PLAY [настройка test-server] ***************************************************

TASK [Gathering Facts] *********************************************************
ok: [test-server]

TASK [apache: пакеты] **********************************************************
changed: [test-server]

TASK [php: пакеты] *************************************************************
changed: [test-server]
...
TASK [vhost: убрать 000-default] ***********************************************
changed: [test-server]

RUNNING HANDLER [apache: reload] ***********************************************
changed: [test-server]

PLAY RECAP *********************************************************************
test-server                : ok=10   changed=9    unreachable=0    failed=0    skipped=0    rescued=0    ignored=0

test-server настроен.
Finished: SUCCESS
```

`test-server-ansible` #3 (повторный запуск, ничего не изменилось, handler не вызван):

```text
PLAY RECAP *********************************************************************
test-server                : ok=9    changed=0    unreachable=0    failed=0    skipped=0    rescued=0    ignored=0
```

`todo-app-cd` #2:

```text
+ ansible-playbook deploy/copy.yml

PLAY [deploy: копирование] *****************************************************
...
TASK [deploy: rsync] ***********************************************************
changed: [test-server]

PLAY RECAP *********************************************************************
test-server                : ok=2    changed=1    unreachable=0    failed=0    skipped=0    rescued=0    ignored=0
...
+ ansible-playbook deploy/configure.yml

PLAY [deploy: настройка] *******************************************************
...
TASK [deploy: автозагрузка] ****************************************************
changed: [test-server]

PLAY RECAP *********************************************************************
test-server                : ok=2    changed=1    unreachable=0    failed=0    skipped=0    rescued=0    ignored=0

todo-app выложен на test-server.
Finished: SUCCESS
```

Сайт:

```text
$ curl -s -i http://localhost:8090/ | head -3
HTTP/1.1 200 OK
...
Server: Apache/2.4.58 (Ubuntu)

$ curl -s -X POST --data-urlencode 'rows[0][title]=отчёт' -d 'rows[0][priority]=3' \
       --data-urlencode 'rows[1][title]=почта' -d 'rows[1][priority]=1' -d 'rows[1][done]=1' \
       http://localhost:8090/ | grep -E 'counters|badge|class="done"'
    <p class="counters">всего 2 · готово 1</p>
                        <span class="badge p3">высокий</span>
                                    <li class="done">почта</li>

$ curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8090/composer.json
404
```

`composer.json` не отдаётся: `DocumentRoot` указывает на `public/`, остальные файлы проекта снаружи не видны. В браузере та же страница: форма, после отправки две колонки.

## Вопросы

### Преимущества Ansible для настройки серверов

| свойство | что это дало в lab05 |
|---|---|
| инвентарь и группы | сервер один, но он в группе `stand`: второй сервер = одна строка в `hosts.ini`, плейбуки не меняются |
| переменные | `group_vars/stand.yml` один на всё: его читают setup, оба deploy-плейбука и шаблон vhost |
| handlers | Apache перезагружается только если изменился vhost или настройки PHP: в сборке #2 handler был, в #3 нет |
| идемпотентность | повторный запуск даёт `changed=0`, плейбук можно запускать сколько угодно раз |
| push по SSH без агента | на test-server нет агента Ansible: ansible-agent заходит туда по обычному SSH под пользователем `ansible` и сам передаёт модули |

### Другие модули Ansible для управления конфигурацией

| задача на сервере | модуль |
|---|---|
| имя хоста | `ansible.builtin.hostname` |
| параметр ядра | `ansible.posix.sysctl` |
| точка монтирования | `ansible.posix.mount` |
| задание cron | `ansible.builtin.cron` |
| правило файрвола | `ansible.builtin.iptables` |
| перезагрузка и ожидание | `ansible.builtin.reboot` |
| часовой пояс | `community.general.timezone` |

В lab05 ни один из них не используется. Использованы `apt`, `service`, `template`, `file`, `command` и `ansible.posix.synchronize`.

### Проблемы при создании плейбука и решения

| что было | почему | что изменено |
|---|---|---|
| первый ручной запуск `deploy/copy.yml` в контейнере агента (`docker compose exec -u jenkins ansible-agent ...`, ещё до pipeline): `sudo: rsync: command not found`, `rsync error: error in rsync protocol data stream (code 12)` | `synchronize` запускает `rsync` на обеих сторонах, на test-server он идёт через sudo (`--rsync-path='sudo -u root rsync'`), а там rsync не было | `rsync` добавлен в `tool_packages` (`group_vars/stand.yml`), его ставит `setup_test_server.yml` |
| при запуске плейбука предупреждение: `Host 'test-server' is using the discovered Python interpreter at '/usr/bin/python3.12', but future installation of another Python interpreter could cause a different interpreter to be discovered.` | Ansible сам ищет python на сервере и предупреждает, что выбор может поменяться | `ansible_python_interpreter: /usr/bin/python3` в `group_vars/stand.yml` |
| первые сборки всех трёх заданий упали: `Composer could not find a composer.json file`, `the playbook: setup_test_server.yml could not be found` | блок `parameters` Jenkins узнаёт только из первой сборки, она шла со значениями по умолчанию (`main` на GitHub, где lab05 ещё не было) | вторая и дальше: Build with Parameters с нужной веткой |

Ограничение стенда: на Docker Desktop файл `/run/secrets/ansible_to_test` внутри контейнера виден пользователю `jenkins` как его собственный (права 600). На Linux у bind mount остаётся uid владельца на хосте, и `jenkins` (uid 1001) сможет прочитать ключ, только если uid совпадает или права шире.

## Версии

| компонент | версия |
|---|---|
| Jenkins | 2.580.1 (Java 21) |
| ansible / ansible-core | 14.5.0 / 2.21.5 (PPA), `ansible.posix` 2.2.2 |
| ansible-agent | Ubuntu 24.04, OpenJDK 21.0.12, git 2.43.0, rsync 3.2.7 |
| test-server | Ubuntu 24.04, Apache 2.4.58, PHP 8.3.6, composer 2.7.1, OpenSSH 9.6p1 |
| ssh-agent | PHP 8.4.27, Composer 2.10.3, PHPUnit 12.5.42 |

## Остановка

```bash
docker compose down -v --rmi local
```

Удаляются контейнеры, тома (в том числе настройки Jenkins) и три собранных образа. Ключи в `secrets/` и `.env` остаются, при следующем запуске `setup.sh` их не пересоздаёт.
