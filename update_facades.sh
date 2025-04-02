#!/bin/bash

# Update Response facade
find /Users/jeffstokoe/GiT/pbx3api/app/Http/Controllers -type f -name "*.php" -exec sed -i '' 's/use Response;/use Illuminate\\Support\\Facades\\Response;/g' {} +

# Update Validator facade
find /Users/jeffstokoe/GiT/pbx3api/app/Http/Controllers -type f -name "*.php" -exec sed -i '' 's/use Validator;/use Illuminate\\Support\\Facades\\Validator;/g' {} +

# Update DB facade
find /Users/jeffstokoe/GiT/pbx3api/app/Http/Controllers -type f -name "*.php" -exec sed -i '' 's/use DB;/use Illuminate\\Support\\Facades\\DB;/g' {} +

# Update Log facade
find /Users/jeffstokoe/GiT/pbx3api/app/Http/Controllers -type f -name "*.php" -exec sed -i '' 's/use Log;/use Illuminate\\Support\\Facades\\Log;/g' {} +

# Update Storage facade
find /Users/jeffstokoe/GiT/pbx3api/app/Http/Controllers -type f -name "*.php" -exec sed -i '' 's/use Storage;/use Illuminate\\Support\\Facades\\Storage;/g' {} +
