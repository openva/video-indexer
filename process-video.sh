#!/bin/bash
if [ -z "$1" ]; then
	echo usage: $0 filename chamber width, omitting .mp4 extension
	exit
fi
if [ -z "$2" ]; then
	echo usage: $0 filename chamber width, omitting .mp4 extension
	exit
fi

SRC=$1
CHAMBER=$2
WIDTH=$3

if [ $CHAMBER = "house" ]; then
	FRAMESTEP=150
else
	FRAMESTEP=60
fi

if [ $WIDTH = "" ]; then
	WIDTH=720
fi

mplayer -vf framestep=$FRAMESTEP -framedrop -nosound $SRC.mp4 -speed 100 -vo jpeg:outdir=$SRC
cd $SRC
if [ $CHAMBER = "house" ]; then
	if [ $WIDTH = "720" ]; then
		for f in *.jpg; do mogrify -resize 720x480\! $f; done
		NAME_CROP="341x57+182+345"
		BILL_CROP="231x32+486+66"
	else
		for f in *.jpg; do mogrify -resize 640x480\! $f; done
		NAME_CROP="341x57+161+345"
		BILL_CROP="129x27+438+65"
	fi

	for f in *[0-9].jpg; do convert $f -crop $NAME_CROP +repage -negate -fx '.8*r+.8*g+0*b' -compress none -depth 8 $f.name.jpg; done
	for f in *[0-9].jpg; do convert $f -crop $BILL_CROP +repage -negate -fx '.8*r+.8*g+0*b' -compress none -depth 8 $f.bill.jpg; done

else
	for f in *.jpg; do mogrify -resize 640x480\! $f; done
	for f in *[0-9].jpg; do convert $f -crop 285x53+193+350 +repage -negate -fx '.8*r+.8*g+0*b' -compress none -depth 8 $f.name.jpg; done
	for f in *[0-9].jpg; do convert $f -crop 129x27+69+69 +repage -negate -fx '.8*r+.8*g+0*b' -compress none -depth 8 $f.bill.jpg; done
fi

find . -type f -name '*.name.jpg' -exec tesseract {} {} \;
find . -type f -name '*.bill.jpg' -exec tesseract {} {} \;
