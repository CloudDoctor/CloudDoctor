FROM alpine:3.6
ADD start_runit /sbin/
RUN mkdir /etc/container_environment && \
    chmod a+x /sbin/start_runit && \
    mkdir /etc/service && \
    mkdir /etc/runit_init.d && \
    apk add --update \
    runit \
    php7 \
    php7-common \
    php7-openssl \
    php7-json \
    php7-zip \
    php7-curl \
    bash \
    curl \
    bind-tools \
    iptables \
    net-tools \
    tcpdump \
    gawk \
    rsync \
    openssl \
    openvpn \
    python2 \
    py-pyldap \
    py-paramiko \
    py-requests \
    py2-pip \
    wget \
    curl \
    zip \
    && \
    rm -rf /var/cache/apk/* && \
    \
    \
    openvpn --version | head -1 | cut -d " " -f1-2 && \
    php --version | head -1 | cut -d "-" -f 1 && \
    host -V && \
    wget --version | head -1 && \
    curl --version | head -1 | cut -d " " -f1-2 && \
    python --version && \
    bash --version | head -1 | cut -d "(" -f1

CMD ["/sbin/start_runit"]

WORKDIR /app

# Copy cron & worker tasks into location and chmod accordingly.
ADD ./ /app/

ADD babysitter.sh /etc/service/babysitter/run
RUN chmod +x /etc/service/babysitter/run
