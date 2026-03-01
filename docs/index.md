# Introduction

**For AI/agent context and recent session summary** (schema yardstick, API conventions, what's done/left), see **pbx3spa/workingdocs/README.md** (then SESSION_HANDOFF.md and PROJECT_PLAN.md). **Workspace:** pbx3-master is a holding folder, not a git repo; the repos are pbx3, pbx3api, pbx3cagi, pbx3spa—commit inside the relevant repo. **Recent:** Queue audit done (model $fillable, pkey 3–5 digits “Queue Dial”, cname/outcome updateable); **move_request_to_model** (Helper.php) now uses `$request->input($key)` for each updateable column so JSON PUT body is applied for all fields; **PLAN_MODELS_AND_VALIDATION_HARMONISATION.md** requires updating SPA with API when converting each table.

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