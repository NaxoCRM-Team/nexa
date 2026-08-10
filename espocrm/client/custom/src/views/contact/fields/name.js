define('custom:views/contact/fields/name', ['views/fields/person-name'], Dep => {
    /**
     * Contact names use a compact salutation and equal first/last-name inputs.
     * Keeping this override at field level avoids changing Espo's shared control.
     */
    return class extends Dep {
        editTemplate = 'custom:contact/fields/name/edit';
    };
});
