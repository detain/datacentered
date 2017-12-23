#!/bin/bash
if [ -e /etc/apt ]; then
	apt install libnghttp2-dev libnghttp2-14 libssl-dev libssl1.0.0 openssl re2c -y
else
	yum install libnghttp2 libnghttp2-devel -y
fi;
rm -rf swoole-src;
git clone https://github.com/swoole/swoole-src.git && \
cd swoole-src && \
phpize && \
./configure --enable-openssl --enable-swoole --with-swoole  --enable-thread --enable-http2 && \
make && \
make test && \
echo "make install;" |sudo -s && \
if [ -e /etc/php/7.0/mods-available ]; then
 echo 'echo "extension=swoole.so" > /etc/php/7.0/mods-available/swoole.ini; phpenmod -v ALL -s ALL swoole;' | sudo -s
elif [ -e /etc/php5/mods-available ]; then
 echo 'echo "extension=swoole.so" > /etc/php5/mods-available/swoole.ini; php5enmod -s ALL swoole;' | sudo -s
else
 echo 'echo "extension=swoole.so" > /etc/php.d/swoole.ini;' | sudo -s
fi && \
cd .. && \
rm -rf swoole-src;
