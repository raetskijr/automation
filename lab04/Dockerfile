# --- база ---
FROM jenkins/ssh-agent:jdk21

# --- php ---
# php-cli + расширения для phpunit (xml: dom/xmlwriter, mbstring) и php-zip для composer
RUN apt-get update \
 && apt-get install -y --no-install-recommends php-cli php-xml php-mbstring php-zip \
 && rm -rf /var/lib/apt/lists/*

# --- composer ---
# официальный установщик -> /usr/local/bin/composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
 && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
 && php -r "unlink('composer-setup.php');"
