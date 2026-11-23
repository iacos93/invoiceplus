#!/bin/sh
# deploy-remote — lancia tools/deploy.sh sul server dal proprio computer.
#
# Il deploy in sé non chiede password (git legge un repository pubblico, il
# reload di FPM ha la sua regola sudoers NOPASSWD): la password arrivava dai
# passaggi a mano prima, cioè l'accesso al server e il cambio d'utente. Qui
# entrambi passano dalla chiave SSH dell'utente amministrativo, che ha sudo
# senza password, e lo script gira comunque come utente del sito, non root.
#
# Host e percorso non stanno nel repository, che è pubblico: si passano per
# ambiente o si salvano una volta in ~/.config/invoiceplus/deploy.conf, es.
#
#   DEPLOY_HOST=ubuntu@server.example
#   DEPLOY_USER=invoiceplus
#   DEPLOY_DIR=/home/invoiceplus/htdocs/esempio.it
#
# Uso: tools/deploy-remote.sh   (gli argomenti extra vanno a ssh, es. -p 2222)
set -eu

conf="${XDG_CONFIG_HOME:-$HOME/.config}/invoiceplus/deploy.conf"
# shellcheck disable=SC1090
[ -f "$conf" ] && . "$conf"

: "${DEPLOY_HOST:?imposta DEPLOY_HOST (es. ubuntu@server) o scrivilo in $conf}"
: "${DEPLOY_DIR:?imposta DEPLOY_DIR (cartella del sito sul server) o scrivilo in $conf}"
DEPLOY_USER="${DEPLOY_USER:-invoiceplus}"

# BatchMode: se la chiave non basta meglio un errore subito che un prompt di
# password, che è proprio ciò che questo script deve togliere. -t serve
# perché deploy.sh scriva l'avanzamento riga per riga.
exec ssh -t -o BatchMode=yes "$@" "$DEPLOY_HOST" \
    "sudo -n -iu '$DEPLOY_USER' sh -c 'cd \"$DEPLOY_DIR\" && tools/deploy.sh'"
