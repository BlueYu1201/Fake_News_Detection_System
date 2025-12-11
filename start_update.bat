@echo off
cd /d "%~dp0"
:: 檢查 PHP 是否在環境變數中，如果沒有，請將下方的 php 改成完整路徑 (例如 C:\xampp\php\php.exe)
php update_hot_topics.php