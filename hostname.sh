#!/bin/bash
hostname -F /etc/hostname
cp /etc/bash.bashrc /etc/bash.bashrc.backup
sed -i 's/\\h/\\e\[31m\\H\\e\[0m/' /etc/bash.bashrc
sed -i 's/\\u/\\e\[5m\\u\\e\[0m/' /etc/bash.bashrc