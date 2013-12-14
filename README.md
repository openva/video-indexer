# Video Indexer

## Introduction

This is a proof-of-concept for extracting chyrons from video ([here's an example video with chyrons](https://archive.org/details/senate20130121)), OCRing them, auto-correcting those strings against a known corpus of data, and storing them in a database. This process has been in use on [Richmond Sunlight](http://www.richmondsunlight.com/) for some years now. See `process-video.sh` for the shell script that does the heavy lifting, and `resolve-chyrons.php` for the PHP that takes the OCRed chyrons, corrects them, and inserts them into the database. (For simplicity's sake, `resolve-chyrons.php` includes only the code to correct and store bill numbers, and not the code to do the same for legislators' names.)

## Programs Required

* [MPlayer](http://www.mplayerhq.hu/) (tested on v1.0-0.48)
* [ImageMagick](http://www.imagemagick.org/) (tested on v6.2.8)
* [Tesseract](https://code.google.com/p/tesseract-ocr/) (tested on v3.02.02)

The functionality employed here is fundamental to all three programs, and is unlikely to be affected by the versions that are used.

## The Process

This is a narrative of the process employed by these two files.

### Take Screenshots

This is done with [MPlayer](http://www.mplayerhq.hu/). I have it play through the video, and save a screenshot every few seconds. If it’s Senate video, a screenshot is saved every 60 frames (or two seconds), and if it’s House video, it’s every 150 frames (or five seconds). That’s because the House video production team keeps the chyrons up for the entire time that a bill is being discussed or a legislator is speaking, while the Senate video production team apparently relishes flashing them for as little time as possible. (“Chyron”? Vocab time! This is the text that you see on TV, such as during a newscast, which uses them to identifying the speaker. The Chyron Corporation came up with the idea of putting graphics on TV screens, rather than filming paper cards. Their name has become synonymous with graphic overlaid on video. Chyrons are also known as “lower thirds.”) Senate chyrons stick around for as little as two seconds, and average around three. This can take half an hour or an hour to run and, when it’s done, I’ve got a directory full of JPEGs, anywhere from one to four thousand of them. I do this like such:

```
mplayer -vf framestep=60 -framedrop -nosound video.mp4 -speed 100 -vo jpeg:outdir=video
```

Selecting a screenshot more or less at random, what gets output are files that look like this:

![screenshot](http://waldo.jaquith.org/blog/wp-content/uploads/2011/02/screenshot.jpg)

Just to be careful, I use the brilliant ImageMagick at this point (and, in fact, for the next few steps) to make sure that the screenshots are at a universal size: 642 by 480.

```
for f in *.jpg; do mogrify -resize 642x480 $f; done
```

### Extract Chyrons

From every one of these frames, I need to cut out the two areas that could contain chyrons. I say “could” because I don’t, at this point, have any idea if there’s a chyron in any of these screenshots. The point of these next couple of steps is to figure that out. So I use ImageMagick again, this time to make two new images for each screenshot, one of the area of the image where a bill number could be located, and one of the area where the speaker’s name could appear. The House and the Senate put these in different locations. Here is how I accomplish this for the House:

```
for f in *[0-9].jpg; do convert $f -crop 341x57+161+345 +repage -negate -fx '.8*r+.8*g+0*b' -compress none -depth 8 $f.name.jpg; done
for f in *[0-9].jpg; do convert $f -crop 129x27+438+65" +repage -negate -fx '.8*r+.8*g+0*b' -compress none -depth 8 $f.bill.jpg; done
```

Instead of a few thousand images, now I have three times as many. The bill chyron images look like this:

![bill chyron](http://waldo.jaquith.org/blog/wp-content/uploads/2011/02/bill.gif)

And the speaker chyron images look like this:

![name chyron](http://waldo.jaquith.org/blog/wp-content/uploads/2011/02/name.gif)

### Determine Chyron Color

Now I put these chyrons to work. As you can see in the above screenshot, chyron text has a background color behind it. In the Senate, it’s maroon, and in the House, it’s blue. This is good news, because it allows me to check for that color to know if either the bill or the legislator chyron is on the screen in this screenshot. Again with ImageMagick I take a one pixel sample of the image, pipe it through the [sed](http://www.gnu.org/software/sed/) text filter, and save the detected color to a file. This is done for every single (potential) chyron image:

```
for f in *.tif; do convert $f -crop 1x1+1+1 -depth 8 txt:- | sed -n 's/.* \(#.*\)/\1/p' > $f.color.txt; done
```

And that means that yet another few thousand files are in my screenshot directory. Looking through each of those text files will tell me whether the corresponding JPEG contains a chyron or not. For the example bill chyron image, the color is `#525f8c`; for the speaker chyron image, it’s `#555f94`. (Those are [hexadecimal triplets](http://en.wikipedia.org/wiki/Web_colors#Hex_triplet).) It is *possible* that a similar shade of red or blue happens to be on that very spot on the screen, so I can get some false positives, but it’s rare and, as you’ll see, not problematic. At this point, though, I still haven’t peered into those files, so I have no idea what’s a chyron and what’s just a random sliver of a screenshot.

### Optimize Chyrons for OCRing

At this point I do something lazy, but simple. I optimize every single (potential) chyron image to be run through optical character recognition (OCR) software and turned into text. If I wanted to be really parsimonious, I would do this after I’d identified which images really are chyrons, but ImageMagick is so fast that I can convert all of these thousands of images in just a few seconds. I convert them all to black and white, dropping out almost all shades of gray, like this:

```
for f in *.tif; do convert $f -negate -fx '.8*r+.8*g+0*b' -compress none -depth 8 $f; done
```

That leaves the bill chyrons looking like this:

![bill chyron](http://waldo.jaquith.org/blog/wp-content/uploads/2011/02/bill-bw.jpg)

And the speaker chyrons looking like this:

![name chyron](http://waldo.jaquith.org/blog/wp-content/uploads/2011/02/name-bw.gif)

### OCR the Chyrons

Still without knowing which of these images are really text-bearing chyrons, I run every one of them through the free, simple, and excellent [Tesseract](http://code.google.com/p/tesseract-ocr/) OCR software. I have tried every Unix-based OCR package out there, subjecting them to rigorous testing, and nothing is nearly as good as Tesseract. This spits out a small text file for each file. Any file that has a chyron will have its text recorded more or less faithfully. Any file that doesn’t have a chyron, Tesseract will still faithfully attempt to find words in, which usually amounts of spitting out nonsense text. That OCRing is done, simply, like this:

```
find . -type f -name '*.name.jpg' -exec tesseract {} {} \;
find . -type f -name '*.bill.jpg' -exec tesseract {} {} \;
```

To recap, we have a screenshot every few seconds, two potentially chyron-bearing files cropped out of each of those screenshots, a text file containing the background color of every one of those potential chyrons, and another text file for every potential chyron that contains OCRd text.

### Identify and Save the Chyrons

At this point it’s all turned over to code that I wrote in PHP. It iterates through this big pile of files, checking to see if the color is close enough to the appropriate shade of red or blue and, if so, pulling the OCRd text out of the file containing it and loading it into a database. There is a record for every screenshot, containing the screenshot itself, the timestamp at which it’s been recorded, and the text as OCRd.

I also use MPlayer’s `-identify` flag to gather all of the data about the video that I can get, and store all of that in the database. Resolution, frames per second, bit rate, and so on.

The chyron that I’ve been using as an example, for Del. Jennifer L. McClellan, OCRed particularly badly, like this:

> Del. Jennifer L i\1cCie1ian
> Richmond City (071)

### Spellcheck

Although Tesseract’s OCR is better than anything else out there, it’s also pretty bad, by any practical measurement. A legislator who speaks for five minutes could easily have their name OCRd fifty different ways in that time. Helping nothing, each chamber has ways of referring to legislators by which they are never referred to by the General Assembly at any other time. Sen. Dave Marsden is mysteriously referred to as “Senator Marsden (D) Western Fairfax.” Not “Dave” Marsden—unlike anywhere on the legislature’s website, he doesn’t get a first name. And “Western” Fairfax? His district municipality is never referred to as that *anywhere* else by the legislature. So how am I to associate that chyron content with Sen. Marsden?

The solution was to train it. I make a first pass on the speaker chyrons and calculate the [Levenshtein distance](http://en.wikipedia.org/wiki/Levenshtein_distance) for each one, relative to a master list of all legislators, with their names formatted similarly, and match any that are within 15% of identical. I make a second pass and see if any unresolved chyrons are the same as any past chyrons that were identified. And I make a third pass, basically repeating the second, only this time calculating the Levenshtein distance and accepting anything within 15%. In this way, the spellcheck gets a little smarter every time that it runs, and does quite well at recognizing names that OCR badly. The only danger is that two legislators with very similar names will represent the same municipality, and the acceptable range of misspellings of their names will get close enough that the system won’t be able to tell them apart. I keep an eye out for that.

### Put the Pieces Together

What I’m left with is a big listing of every time that a bill or speaker chyron appeared on the screen, and the contents of those chyrons, which I then tie back to the database of legislators and bills to allow video to be sliced and diced dynamically based on that knowledge. (For example, every bill page features a highlights reel of all of the video of that bill being discussed on the floor of the legislature—[here’s a random example](http://www.richmondsunlight.com/bill/2011/hb1428/#video)—courtesy of [HTTP pseudo-streaming](http://www.longtailvideo.com/support/jw-player/jw-player-for-flash-v5/12534/video-delivery-http-pseudo-streaming)). This also enabled some other fun things, such as calculating how many times each legislator has spoken, how long they’ve spoken, which subjects get the most time devoted to them on the floor, and lots of other toys that I haven’t had time to implement yet, but plenty of time to dream up.

Everything after uploading the video until the spellcheck is done with a single shell script, which is to say that it’s automated. And everything after that is done with a PHP script. So all of these steps are actually pretty easy, and require a minimal amount of work on my part.

And that’s how a video gets turned into thousands of data points.
