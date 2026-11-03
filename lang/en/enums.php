<?php

return [
    'content_status' => [
        'pending' => 'Awaiting moderation',
        'approved' => 'Published',
        'rejected' => 'Rejected',
        'spam' => 'Spam',
    ],
    'vote_dimension' => [
        'agreement' => 'Agree or disagree',
        'helpfulness' => 'Helpful or not helpful',
        'needs_source' => 'Needs a source',
    ],
    'editor_tool' => [
        'bold' => 'Bold',
        'italic' => 'Italic',
        'underline' => 'Underline',
        'strike' => 'Strikethrough',
        'bullet_list' => 'Bulleted list',
        'ordered_list' => 'Numbered list',
        'quote' => 'Quote',
        'heading' => 'Heading',
        'code' => 'Code',
        'link' => 'Link',
        'emoji' => 'Emoji',
        'image' => 'Upload images',
        'sticker' => 'Stickers',
        'product_card' => 'Product card',
    ],
    'discussion_sort' => [
        'helpful' => 'Most helpful',
        'newest' => 'Newest first',
        'oldest' => 'Oldest first',
        'controversial' => 'Controversial',
    ],
    'review_sort' => [
        'helpful' => 'Most helpful',
        'newest' => 'Newest first',
        'highest' => 'Highest score',
        'lowest' => 'Lowest score',
    ],
    'report_status' => [
        'open' => 'Open',
        'resolved' => 'Resolved',
        'dismissed' => 'Dismissed',
    ],
    'report_reason' => [
        'spam' => 'Spam or advertising',
        'offensive' => 'Offensive or abusive',
        'off_topic' => 'Off topic',
        'personal_data' => 'Contains personal data',
        'misinformation' => 'Misleading information',
        'health_claim' => 'Unfounded health claim',
        'other' => 'Something else',
    ],
    'comparison' => [
        'better' => 'Better',
        'worse' => 'Worse',
        'same' => 'About the same',
    ],
    'facet' => [
        'purchase' => [
            'label' => 'Purchase',
            'first_time' => 'Bought for the first time',
            'regular' => 'Regular buyer',
        ],
        'deal' => [
            'label' => 'Price',
            'on_offer' => 'Bought on offer',
            'regular_price' => 'Bought at the regular price',
        ],
    ],
    'question_type' => [
        'scale' => 'Scale',
        'choice' => 'One choice',
        'multi' => 'Several choices',
    ],
];
