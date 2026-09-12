# Accelerate

A Redis cache plug-in for Typecho.

## Introduction

Current language: **English** | [简体中文](/README_CN.md)

### HighLight

* Support Typecho 1.2 and above.
* Support PHP 8.2 and above.
* Follow the Typecho official plugin development specification, using namespace instead of the old method;
* Cache article content by access uri to improve the speed of repeated access;
* Design with lightweight, better performance;

### Requirements

* Typecho 1.2 or later;
* PHP 8.2 or later;
* PHP Redis extension (phpredis 6.x recommended);
* A reachable, writable Redis server.

### Usage

After confirming that the requirements above are met, download the source code or clone the repository into `usr/plugins/`. The plug-in directory name MUST be `Accelerate`; then activate it in the admin panel.

### Update

If you want to update the plug-in, please disable the plug-in first.

If you install the plug-in via git command, execute the fallow command in the plug-in directory:

```bash
git pull --rebase
```

## Author

Thanks to all contributors:

<br>
<a href="https://github.com/vndroid/Accelerate/graphs/contributors">
<img src="https://contrib.rocks/image?repo=vndroid/Accelerate" alt="contributors"/>
</a>
