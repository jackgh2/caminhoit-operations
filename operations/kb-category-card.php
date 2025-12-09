<div class="col-md-6 col-lg-4 mb-4 category-card" data-category-id="<?= $category['id'] ?>">
    <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
            <div class="d-flex align-items-start justify-content-between mb-3">
                <div class="d-flex align-items-center">
                    <div class="category-icon me-3" style="background-color: <?= htmlspecialchars($category['color']) ?>">
                        <i class="<?= htmlspecialchars($category['icon'] ?: 'bi-folder') ?>"></i>
                    </div>
                    <div>
                        <h6 class="mb-1 fw-bold"><?= htmlspecialchars($category['name']) ?></h6>
                        <?php if ($category['parent_name']): ?>
                            <small class="text-muted">
                                <i class="bi bi-arrow-return-right"></i>
                                <?= htmlspecialchars($category['parent_name']) ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item" href="#" onclick="editCategory(
                                <?= $category['id'] ?>, 
                                '<?= htmlspecialchars($category['name'], ENT_QUOTES) ?>', 
                                '<?= htmlspecialchars($category['description'], ENT_QUOTES) ?>', 
                                <?= $category['parent_id'] ? $category['parent_id'] : 'null' ?>, 
                                '<?= htmlspecialchars($category['icon'], ENT_QUOTES) ?>', 
                                '<?= htmlspecialchars($category['color'], ENT_QUOTES) ?>',
                                <?= $category['is_active'] ? 'true' : 'false' ?>
                            )">
                                <i class="bi bi-pencil me-2"></i>Edit
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/operations/kb-articles.php?category=<?= $category['id'] ?>">
                                <i class="bi bi-file-text me-2"></i>View Articles
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="#" onclick="deleteCategory(
                                <?= $category['id'] ?>, 
                                '<?= htmlspecialchars($category['name'], ENT_QUOTES) ?>'
                            )">
                                <i class="bi bi-trash me-2"></i>Delete
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <?php if ($category['description']): ?>
                <p class="text-muted small mb-3"><?= htmlspecialchars($category['description']) ?></p>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <span class="badge bg-primary me-2">
                        <?= $category['article_count'] ?> articles
                    </span>
                    <?php if (!$category['is_active']): ?>
                        <span class="badge bg-secondary">Inactive</span>
                    <?php endif; ?>
                </div>
                
                <div class="text-muted small">
                    <i class="bi bi-grip-vertical"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Subcategories -->
    <?php if (isset($category['children']) && !empty($category['children'])): ?>
        <div class="mt-2">
            <?php foreach ($category['children'] as $subcategory): ?>
                <div class="card border-0 shadow-sm subcategory-card mb-2" data-category-id="<?= $subcategory['id'] ?>">
                    <div class="card-body py-2">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center">
                                <div class="category-icon me-2" style="background-color: <?= htmlspecialchars($subcategory['color']) ?>; width: 24px; height: 24px; font-size: 0.8rem;">
                                    <i class="<?= htmlspecialchars($subcategory['icon'] ?: 'bi-folder') ?>"></i>
                                </div>
                                <small class="fw-medium"><?= htmlspecialchars($subcategory['name']) ?></small>
                                <span class="badge bg-light text-dark ms-2"><?= $subcategory['article_count'] ?></span>
                            </div>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-three-dots"></i>
                                </button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="editCategory(
                                            <?= $subcategory['id'] ?>, 
                                            '<?= htmlspecialchars($subcategory['name'], ENT_QUOTES) ?>', 
                                            '<?= htmlspecialchars($subcategory['description'], ENT_QUOTES) ?>', 
                                            <?= $subcategory['parent_id'] ?>, 
                                            '<?= htmlspecialchars($subcategory['icon'], ENT_QUOTES) ?>', 
                                            '<?= htmlspecialchars($subcategory['color'], ENT_QUOTES) ?>',
                                            <?= $subcategory['is_active'] ? 'true' : 'false' ?>
                                        )">
                                            <i class="bi bi-pencil me-2"></i>Edit
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item text-danger" href="#" onclick="deleteCategory(
                                            <?= $subcategory['id'] ?>, 
                                            '<?= htmlspecialchars($subcategory['name'], ENT_QUOTES) ?>'
                                        )">
                                            <i class="bi bi-trash me-2"></i>Delete
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>