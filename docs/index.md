# Introduction
## Background
pbx3api is a fairly vanilla OAS JSON API. It allows you to programmatically do anything you can do manually at the pbx3 browser. <br/>

Using the API you may...

* Create custom extensions and new features. 
* Rewrite all or parts of the pbx3 browser to create a package of your own. 
* Control a remote Asterisk from your app. 
* Control a remote pbx3 from a management layer.
* Create an interface to a third party software package. 

All of these things are possible with the API.

## Requirements
pbx3api requires a pbx3 V65 instance running php 8.2 or later.  Only 64 bit architectures are supported for both X86 and ARM and the API uses the Laravel 11 framework. The support code is written in a mix of standalone PHP, bash and C code.