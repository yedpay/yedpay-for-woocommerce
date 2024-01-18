#!/bin/bash
DIRECTORY=$(realpath "$(dirname "${BASH_SOURCE[0]}")")/..
ZIP_FILE="$DIRECTORY/../yedpay-for-woocommerce.zip"
cd $DIRECTORY

if [[ -f $ZIP_FILE ]]; then
    rm $ZIP_FILE
    echo "$ZIP_FILE exist"
fi

composer update

zip -r $ZIP_FILE images languages vendor index.php LICENSE readme.txt WoocommerceYedpay.php
