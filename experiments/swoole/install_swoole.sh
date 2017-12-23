#!/bin/bash
cd && \
if [ $(php -v|head -n 1|cut -d " " -f2|cut -d\. -f1-2|sed s#"\."#""#g) -ge 55 ]; then 
 if [ ! -e swoole-src ]; then \
  git clone https://github.com/swoole/swoole-src;
 fi && \
 cd swoole-src && \
 git pull --all && \
 git submodule update --init --recursive && \
 git submodule sync --recursive; 
else 
 pecl download swoole; 
 tar xvzf swoole-*tgz; 
 cd swoole-*; 
fi && \
phpize && \
./configure --with-php-config=/usr/bin/php-config --enable-swoole --enable-openssl --with-openssl-dir=/usr --enable-http2 $(if [ $(php -v|head -n 1|cut -d " " -f2|cut -d\. -f1-2|sed s#"\."#""#g) -ge 55 ]; then echo --enable-coroutine; fi) && \
make && \
make install && \
echo "extension=swoole.so" > $(php -i|grep "dir for additional .ini files"|sed s#"^[^/]*"#""#g)/swoole.ini
