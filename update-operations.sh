#!/bin/bash

# Script to update all operations/*.php files to use new header and footer

OPERATIONS_DIR="C:\Users\jaque\Documents\claude\caminhoit-refresh\operations"

# List of files to update
files=(
  "chat-admin.php"
  "create-invoice.php"
  "create-order.php"
  "create-quote.php"
  "edit-order.php"
  "edit-quote.php"
  "feedback-analytics.php"
  "invoices.php"
  "invoices2.php"
  "kb-articles-create.php"
  "kb-articles-edit.php"
  "kb-articles.php"
  "kb-categories.php"
  "kb-category-card.php"
  "kb-dashboard.php"
  "kb-feedback.php"
  "kb-settings.php"
  "manage-companies.php"
  "manage-groups.php"
  "manage-subscriptions.php"
  "manage-users.php"
  "orders.php"
  "payments.php"
  "pending-orders.php"
  "product-assignments.php"
  "promo-codes.php"
  "quotes.php"
  "service-catalog.php"
  "staff-analytics.php"
  "staff-view-ticket.php"
  "staff-view-ticket2.php"
  "view-order.php"
  "view-quote.php"
  "view-quote2.php"
)

for file in "${files[@]}"; do
  filepath="$OPERATIONS_DIR/$file"

  if [ ! -f "$filepath" ]; then
    echo "Skipping $file - not found"
    continue
  fi

  echo "Processing $file..."

  # Find line number where <!DOCTYPE or <html starts
  doctype_line=$(grep -n "<!DOCTYPE\|<html" "$filepath" | head -1 | cut -d: -f1)

  if [ -z "$doctype_line" ]; then
    echo "  No HTML structure found in $file - skipping"
    continue
  fi

  # Find line number where nav include or body tag is
  nav_line=$(grep -n "include.*nav\|<body" "$filepath" | head -1 | cut -d: -f1)

  if [ -z "$nav_line" ]; then
    echo "  No nav/body found in $file - skipping"
    continue
  fi

  # Line before doctype (where PHP ends)
  php_end_line=$((doctype_line - 1))

  # Extract the PHP part (lines 1 to php_end_line)
  sed -n "1,${php_end_line}p" "$filepath" > "${filepath}.tmp"

  # Add page title and header include
  echo '<?php $page_title = "Operations | CaminhoIT"; ?>' >> "${filepath}.tmp"
  echo '<?php include $_SERVER['"'"'DOCUMENT_ROOT'"'"'] . '"'"'/includes/header-v2-auth.php'"'"'; ?>' >> "${filepath}.tmp"
  echo '' >> "${filepath}.tmp"

  # Add the content after nav line
  content_start_line=$((nav_line + 1))
  sed -n "${content_start_line},\$p" "$filepath" >> "${filepath}.tmp"

  # Replace footer includes or closing body/html tags
  sed -i "s|<?php include \$_SERVER\['DOCUMENT_ROOT'\] \. '/includes/footer\.php'; ?>|<?php include \$_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>|g" "${filepath}.tmp"
  sed -i "s|</body>.*</html>|<?php include \$_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>|g" "${filepath}.tmp"

  # Replace the original file
  mv "${filepath}.tmp" "$filepath"

  echo "  ✓ Updated $file"
done

echo "All files processed!"
